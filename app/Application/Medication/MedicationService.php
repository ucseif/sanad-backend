<?php

namespace App\Application\Medication;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\DosePolicy;
use App\Policies\MedicationPolicy;
use Carbon\CarbonImmutable as Time;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MedicationService
{
    public function handle(Request $request, array $input): array
    {
        return DB::transaction(function () use ($request, $input): array {
            $op = str_replace('medication.', '', $request->route()->getName());
            $actor = $request->user();
            $patient = $this->patient($actor, (string) $request->route('patient'));
            if (! $request->isMethod('GET') && ! app(MedicationPolicy::class)->manage($actor, $patient)) {
                throw new MedicationFailure('forbidden', 403);
            }
            $med = $request->route('medication') ? $this->medication($patient->id, $request->route('medication')) : null;
            $dose = $request->route('dose') ? $this->dose($patient->id, $request->route('dose')) : null;
            if (in_array($op, ['settings-update', 'consent', 'taken', 'skipped', 'late-intake', 'corrections', 'prn'], true)
                && ! (in_array($op, ['settings-update', 'consent'], true)
                    ? app(MedicationPolicy::class)->consent($actor, $patient) : app(DosePolicy::class)->record($actor, $patient))) {
                throw new MedicationFailure('forbidden', 403);
            }
            $key = $request->header('Idempotency-Key');
            $hash = null;
            if (! $request->isMethod('GET')) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$actor->id.'|'.$key]);
                $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
                $previous = DB::table('medication_mutation_requests')->where('actor_id', $actor->id)->where('request_key', $key)->first();
                if ($previous) {
                    if (! hash_equals($previous->request_hash, $hash)) {
                        throw new MedicationFailure('idempotency-conflict', 409);
                    }

                    return [json_decode($previous->response, true, 512, JSON_THROW_ON_ERROR), $previous->status];
                }
            }
            $now = Time::now('UTC');
            $data = match ($op) {
                'settings' => ['settings' => $this->settings($patient->id)],
                'settings-update' => ['settings' => $this->confirmTimezone($patient, $actor, $input, $now)],
                'index' => $this->listing($patient, $actor, $input, $now),
                'store' => ['medication' => $this->create($patient, $actor, $input, $now)],
                'show' => ['medication' => $this->resource($med, $actor, $now)],
                'update' => ['medication' => $this->update($med, $actor, $input, $now)],
                'archive' => ['medication' => $this->archive($med, $actor, $input, $now)],
                'revisions' => $this->revisions($med, $input),
                'reminders', 'consent' => ['reminder_preferences' => $this->preferences($med, $actor, $op, $input, $now)],
                default => app(MedicationTracking::class)->handle($op, $patient, $actor, $med, $dose, $input, $now),
            };
            $status = in_array($op, ['store', 'prn'], true) ? 201 : 200;
            $code = match ($op) {
                'store' => 'medication-created', 'dashboard' => 'medication-dashboard-retrieved',
                default => 'medication-'.$op.'-completed',
            };
            $response = ['code' => $code, 'data' => ['contract_version' => 1, 'server_now' => $now->toIso8601String(), ...$data]];
            if ($hash !== null) {
                DB::table('medication_mutation_requests')->insert(['actor_id' => $actor->id, 'request_key' => $key,
                    'request_hash' => $hash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'status' => $status, 'recorded_at' => $now]);
            }

            return [$response, $status];
        }, 3);
    }

    public function patient(User $actor, string $id): User
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            throw new MedicationFailure('patient-not-found', 404);
        }
        $patient = User::query()->whereKey($id)->lockForUpdate()->first();
        if (! $patient || ! app(MedicationPolicy::class)->view($actor, $patient)) {
            throw new MedicationFailure('patient-not-found', 404);
        }
        if ($actor->role === UserRole::Caregiver) {
            $link = DB::table('caregiver_links')->where('patient_id', $patient->id)->where('caregiver_id', $actor->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $link) {
                throw new MedicationFailure('patient-not-found', 404);
            }
        }

        return $patient;
    }

    public function medication(int $patient, string $id): object
    {
        $med = Str::isUuid($id) ? DB::table('medications')->where('patient_id', $patient)->where('id', $id)->lockForUpdate()->first() : null;
        if (! $med) {
            throw new MedicationFailure('medication-not-found', 404);
        }

        return $med;
    }

    public function dose(int $patient, string $id): object
    {
        $initial = Str::isUuid($id) ? DB::table('medication_dose_occurrences')->where('patient_id', $patient)->where('id', $id)->first() : null;
        if (! $initial) {
            throw new MedicationFailure('dose-not-found', 404);
        }
        $this->medication($patient, $initial->medication_id);

        return DB::table('medication_dose_occurrences')->where('id', $id)->lockForUpdate()->first();
    }

    public function version(object $row, array $input): void
    {
        if ($row->version !== (int) $input['expected_version']) {
            throw new MedicationFailure('version-conflict', 409);
        }
    }

    public function active(object $med): void
    {
        if ($med->archived_at !== null) {
            throw new MedicationFailure('medication-archived', 409);
        }
    }

    public function audit(int $patient, ?string $medication, int $actor, string $action, array $changes, Time $now): void
    {
        DB::table('medication_audit_events')->insert(['id' => (string) Str::uuid(), 'patient_id' => $patient,
            'medication_id' => $medication, 'actor_id' => $actor, 'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'recorded_at' => $now]);
    }

    public function revision(object $row): array
    {
        $times = DB::table('medication_schedule_times')->where('revision_id', $row->id)->orderBy('local_time')->pluck('local_time')->map(fn ($v) => substr($v, 0, 5))->all();
        $days = DB::table('medication_schedule_weekdays')->where('revision_id', $row->id)->orderBy('weekday')->pluck('weekday')->map(fn ($v) => (int) $v)->all();

        return ['id' => $row->id, 'revision_number' => $row->revision_number,
            'prescription' => json_decode($row->prescription, true, 512, JSON_THROW_ON_ERROR),
            'schedule' => ['frequency' => $row->frequency, 'timezone' => $row->timezone, 'start_date' => $row->start_date,
                'end_date' => $row->end_date, 'times' => $times, 'weekdays' => $days, 'interval_hours' => $row->interval_hours,
                'anchor_time' => $row->anchor_time === null ? null : substr($row->anchor_time, 0, 5)],
            'effective_from' => Time::parse($row->effective_from)->utc()->toIso8601String(),
            'effective_to' => $row->effective_to ? Time::parse($row->effective_to)->utc()->toIso8601String() : null,
            'superseded_at' => $row->superseded_at, 'grace_seconds' => $row->grace_seconds, 'grace_policy_version' => $row->grace_policy_version];
    }

    public function resource(object $med, User $actor, Time $now): array
    {
        $revisions = DB::table('medication_schedule_revisions')->where('medication_id', $med->id)->whereNull('superseded_at');
        $current = (clone $revisions)->where('effective_from', '<=', $now)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))->first();
        // Archived records still expose the last authoritative prescription.
        $current ??= (clone $revisions)->orderByDesc('effective_from')->first();
        $pending = (clone $revisions)->where('effective_from', '>', $now)->orderBy('effective_from')->first();
        $preferences = DB::table('medication_preferences')->where('medication_id', $med->id)->first();

        return ['id' => $med->id, 'version' => $med->version, 'archived_at' => $med->archived_at,
            'current_revision' => $current ? $this->revision($current) : null,
            'pending_revision' => $pending ? $this->revision($pending) : null,
            'reminder_preferences' => $this->preferenceResource($preferences),
            'allowed_actions' => $med->archived_at ? ['view', 'view_history'] : ['view', 'update', 'archive', 'change_reminders',
                ...($actor->id === $med->patient_id ? ['change_escalation_consent'] : [])]];
    }

    private function settings(int $patient): array
    {
        $row = DB::table('medication_patient_settings')->where('patient_id', $patient)->first();

        return ['confirmed_timezone' => $row?->confirmed_timezone, 'confirmed_at' => $row?->confirmed_at,
            'version' => $row?->version ?? 0, 'requires_confirmation' => $row === null];
    }

    private function confirmTimezone(User $patient, User $actor, array $input, Time $now): array
    {
        $previous = $this->settings($patient->id);
        if ($previous['version'] !== (int) $input['expected_version']) {
            throw new MedicationFailure('version-conflict', 409);
        }
        DB::table('medication_patient_settings')->updateOrInsert(['patient_id' => $patient->id],
            ['confirmed_timezone' => $input['timezone'], 'confirmed_at' => $now, 'version' => $previous['version'] + 1]);
        $this->audit($patient->id, null, $actor->id, 'timezone-confirmed', ['previous' => $previous['confirmed_timezone'], 'timezone' => $input['timezone']], $now);

        return $this->settings($patient->id);
    }

    private function create(User $patient, User $actor, array $input, Time $now): array
    {
        $schedule = $this->scheduleDefaults($patient->id, $input['schedule']);
        $id = (string) Str::uuid();
        DB::table('medications')->insert(['id' => $id, 'patient_id' => $patient->id, 'version' => 1,
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now]);
        $med = $this->medication($patient->id, $id);
        $this->insertRevision($med, $actor, $input['prescription'], $schedule, $now, $now);
        $prefs = $input['reminder_preferences'] ?? [];
        $consent = (bool) ($prefs['notify_caregiver_if_missed'] ?? false);
        DB::table('medication_preferences')->insert(['medication_id' => $id, 'patient_id' => $patient->id,
            'enable_medication_reminders' => $prefs['enable_medication_reminders'] ?? true,
            'notify_caregiver_if_missed' => $consent, 'escalation_delay_minutes' => $prefs['escalation_delay_minutes'] ?? config('medication.escalation_delay_minutes'),
            'consent_by' => $consent ? $patient->id : null, 'consent_at' => $consent ? $now : null]);
        $this->audit($patient->id, $id, $actor->id, 'created', ['revision' => 1], $now);

        return $this->resource($med, $actor, $now);
    }

    private function scheduleDefaults(int $patient, array $schedule, ?string $previousTimezone = null): array
    {
        $schedule['timezone'] ??= $previousTimezone ?? $this->settings($patient)['confirmed_timezone'];
        if ($schedule['timezone'] === null) {
            throw ValidationException::withMessages(['schedule.timezone' => 'Confirm the patient timezone or explicitly supply an IANA timezone.']);
        }

        return array_replace(['end_date' => null, 'times' => [], 'weekdays' => [], 'interval_hours' => null, 'anchor_time' => null], $schedule);
    }

    private function insertRevision(object $med, User $actor, array $prescription, array $schedule, Time $effective, Time $now): void
    {
        $id = (string) Str::uuid();
        $prescription['instructions'] ??= '';
        $prescription['special_notes'] ??= '';
        $anchor = $schedule['frequency'] === 'every_hours' ? app(Recurrence::class)->resolve($schedule['start_date'], $schedule['anchor_time'], $schedule['timezone']) : null;
        $number = (int) DB::table('medication_schedule_revisions')->where('medication_id', $med->id)->max('revision_number') + 1;
        sort($schedule['times']);
        sort($schedule['weekdays']);
        DB::table('medication_schedule_revisions')->insert(['id' => $id, 'medication_id' => $med->id, 'patient_id' => $med->patient_id,
            'revision_number' => $number, 'prescription' => json_encode($prescription, JSON_THROW_ON_ERROR),
            'recurrence_snapshot' => json_encode(['times' => $schedule['times'], 'weekdays' => $schedule['weekdays']], JSON_THROW_ON_ERROR),
            'frequency' => $schedule['frequency'], 'timezone' => $schedule['timezone'], 'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'], 'interval_hours' => $schedule['interval_hours'], 'anchor_time' => $schedule['anchor_time'],
            'anchor_at' => $anchor, 'effective_from' => $effective, 'created_by' => $actor->id, 'created_at' => $now,
            'grace_seconds' => config('medication.grace_seconds'), 'grace_policy_version' => config('medication.grace_policy_version')]);
        foreach ($schedule['times'] as $time) {
            DB::table('medication_schedule_times')->insert(['revision_id' => $id, 'local_time' => $time]);
        }
        foreach ($schedule['weekdays'] as $day) {
            DB::table('medication_schedule_weekdays')->insert(['revision_id' => $id, 'weekday' => $day]);
        }
    }

    private function update(object $med, User $actor, array $input, Time $now): array
    {
        $this->version($med, $input);
        $this->active($med);
        if (! isset($input['prescription']) && ! isset($input['schedule'])) {
            throw ValidationException::withMessages(['prescription' => 'Supply prescription or schedule changes.']);
        }
        $resource = $this->resource($med, $actor, $now);
        $current = $resource['current_revision'];
        $base = $resource['pending_revision'] ?? $current;
        $effective = $resource['pending_revision'] ? Time::parse($base['effective_from'])
            : app(Recurrence::class)->resolve($now->setTimezone($current['schedule']['timezone'])->addDay()->format('Y-m-d'), '00:00', $current['schedule']['timezone']);
        if ($resource['pending_revision']) {
            DB::table('medication_schedule_revisions')->where('id', $base['id'])->update(['superseded_at' => $now]);
            $ids = DB::table('medication_dose_occurrences')->where('revision_id', $base['id'])->whereNull('resolution')->pluck('id');
            DB::table('medication_dose_occurrences')->whereIn('id', $ids)->update(['cancelled_at' => $now]);
            DB::table('medication_notification_candidates')->whereIn('occurrence_id', $ids)->update(['state' => 'suppressed', 'reason' => 'revision-superseded', 'evaluated_at' => $now]);
        } else {
            DB::table('medication_schedule_revisions')->where('id', $current['id'])->update(['effective_to' => $effective]);
            $ids = DB::table('medication_dose_occurrences')->where('revision_id', $current['id'])->where('scheduled_at', '>=', $effective)->whereNull('resolution')->pluck('id');
            DB::table('medication_dose_occurrences')->whereIn('id', $ids)->update(['cancelled_at' => $now]);
            DB::table('medication_notification_candidates')->whereIn('occurrence_id', $ids)->update(['state' => 'suppressed', 'reason' => 'revision-replaced', 'evaluated_at' => $now]);
        }
        $prescription = array_replace($base['prescription'], $input['prescription'] ?? []);
        $schedule = $this->scheduleDefaults($med->patient_id, $input['schedule'] ?? $base['schedule'], $base['schedule']['timezone']);
        $this->insertRevision($med, $actor, $prescription, $schedule, $effective, $now);
        DB::table('medications')->where('id', $med->id)->update(['version' => $med->version + 1, 'updated_by' => $actor->id, 'updated_at' => $now]);
        $this->audit($med->patient_id, $med->id, $actor->id, 'revision-created', ['replaces' => $base['id'], 'effective_from' => $effective->toIso8601String()], $now);

        return $this->resource($this->medication($med->patient_id, $med->id), $actor, $now);
    }

    private function archive(object $med, User $actor, array $input, Time $now): array
    {
        $this->version($med, $input);
        $this->active($med);
        DB::table('medications')->where('id', $med->id)->update(['archived_at' => $now, 'archived_by' => $actor->id,
            'updated_by' => $actor->id, 'updated_at' => $now, 'version' => $med->version + 1]);
        DB::table('medication_dose_occurrences')->where('medication_id', $med->id)->where('scheduled_at', '>=', $now)->whereNull('resolution')->update(['cancelled_at' => $now]);
        DB::table('medication_notification_candidates')->where('medication_id', $med->id)->update(['state' => 'suppressed', 'reason' => 'medication-archived', 'evaluated_at' => $now]);
        $this->audit($med->patient_id, $med->id, $actor->id, 'archived', [], $now);

        return $this->resource($this->medication($med->patient_id, $med->id), $actor, $now);
    }

    private function listing(User $patient, User $actor, array $input, Time $now): array
    {
        $query = DB::table('medications')->where('patient_id', $patient->id);
        $status = $input['status'] ?? 'active';
        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }
        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate($input['per_page'] ?? 20, ['*'], 'page', $input['page'] ?? 1);

        return ['medications' => $page->getCollection()->map(fn ($med) => $this->resource($med, $actor, $now))->all(), 'pagination' => $this->pagination($page)];
    }

    private function revisions(object $med, array $input): array
    {
        $page = DB::table('medication_schedule_revisions')->where('medication_id', $med->id)->orderByDesc('revision_number')->paginate($input['per_page'] ?? 20, ['*'], 'page', $input['page'] ?? 1);

        return ['revisions' => $page->getCollection()->map(fn ($row) => $this->revision($row))->all(), 'pagination' => $this->pagination($page)];
    }

    private function preferences(object $med, User $actor, string $op, array $input, Time $now): array
    {
        $this->active($med);
        $row = DB::table('medication_preferences')->where('medication_id', $med->id)->lockForUpdate()->first();
        $this->version($row, $input);
        $changes = ['version' => $row->version + 1];
        if ($op === 'reminders') {
            $changes['enable_medication_reminders'] = $input['enable_medication_reminders'];
        } else {
            $changes += ['notify_caregiver_if_missed' => $input['notify_caregiver_if_missed'],
                'escalation_delay_minutes' => $input['escalation_delay_minutes'] ?? $row->escalation_delay_minutes,
                'consent_by' => $actor->id, 'consent_at' => $now, 'consent_version' => $row->consent_version + 1];
        }
        DB::table('medication_preferences')->where('medication_id', $med->id)->update($changes);
        DB::table('medication_notification_candidates')->where('medication_id', $med->id)->update(['state' => 'suppressed', 'reason' => 'preferences-changed', 'evaluated_at' => $now]);
        $this->audit($med->patient_id, $med->id, $actor->id, $op.'-changed', ['before' => $this->preferenceResource($row), 'changes' => $changes], $now);

        return $this->preferenceResource(DB::table('medication_preferences')->where('medication_id', $med->id)->first());
    }

    public function preferenceResource(object $row): array
    {
        return ['version' => $row->version, 'enable_medication_reminders' => $row->enable_medication_reminders,
            'notify_caregiver_if_missed' => $row->notify_caregiver_if_missed, 'escalation_delay_minutes' => $row->escalation_delay_minutes];
    }

    public function pagination($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
