<?php

namespace App\Application\Medication;

use App\Models\User;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MedicationTracking
{
    public function status(object $dose, Time $now): string
    {
        if ($dose->cancelled_at !== null) {
            return 'cancelled';
        }
        if ($dose->resolution !== null) {
            return $dose->resolution;
        }

        return $now < Time::parse($dose->scheduled_at) ? 'pending' : ($now < Time::parse($dose->missed_at) ? 'overdue' : 'missed');
    }

    /** Durable evidence of an unreported dose, never proof of physical non-consumption. */
    public function reconcile(object $dose, Time $now): void
    {
        if ($this->status($dose, $now) !== 'missed') {
            return;
        }
        $reset = DB::table('medication_intake_events')->where('occurrence_id', $dose->id)->where('event_type', 'corrected')->whereNull('new_resolution')->max('recorded_at');
        $effective = $reset ? Time::parse($dose->missed_at)->max(Time::parse($reset)) : Time::parse($dose->missed_at);
        DB::table('medication_intake_events')->insertOrIgnore(['id' => (string) Str::uuid(), 'occurrence_id' => $dose->id,
            'medication_id' => $dose->medication_id, 'patient_id' => $dose->patient_id, 'actor_id' => null,
            'event_type' => 'missed', 'effective_at' => $effective, 'recorded_at' => $now]);
    }

    public function handle(string $op, User $patient, User $actor, ?object $med, ?object $dose, array $input, Time $now): array
    {
        return match ($op) {
            'dashboard' => $this->dashboard($patient, $actor, $input, $now),
            'doses', 'adherence' => $this->listing($patient, $actor, $med, $input, $now),
            'dose' => $this->details($dose, $actor, $now),
            'taken', 'skipped', 'late-intake', 'corrections' => ['dose' => $this->action($op, $dose, $actor, $input, $now)],
            'prn' => ['dose' => $this->prn($med, $actor, $input, $now)],
            default => throw new MedicationFailure('operation-not-found', 404),
        };
    }

    private function bounds(array $input, Time $now): array
    {
        $from = $input['date'] ?? $input['from'];
        $to = $input['date'] ?? $input['to'];
        $calendarFrom = Time::parse($from, 'UTC');
        $calendarTo = Time::parse($to, 'UTC')->addDay();
        if ($calendarFrom->diffInDays($calendarTo) > 31 || $calendarTo->lessThanOrEqualTo($calendarFrom)
            || $to > $now->setTimezone($input['timezone'])->addDays(31)->format('Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'Use at most 31 days, no later than 31 days ahead.']);
        }
        $engine = app(Recurrence::class);

        return [$engine->resolve($from, '00:00', $input['timezone']), $engine->resolve($calendarTo->format('Y-m-d'), '00:00', $input['timezone'])];
    }

    private function occurrences(int $patient, Time $from, Time $to, Time $now): array
    {
        app(DoseMaterializer::class)->between($patient, $from, $to, $now);
        $prnHistoryTime = 'COALESCE(medication_dose_occurrences.actual_intake_at, (SELECT e.actual_intake_at FROM medication_intake_events e WHERE e.occurrence_id=medication_dose_occurrences.id AND e.actual_intake_at IS NOT NULL ORDER BY e.sequence DESC LIMIT 1))';
        $rows = DB::table('medication_dose_occurrences')->where('patient_id', $patient)->whereNull('cancelled_at')
            ->where(function ($q) use ($from, $to, $prnHistoryTime): void {
                $q->where(fn ($q) => $q->where('kind', 'scheduled')->where('scheduled_at', '>=', $from)->where('scheduled_at', '<', $to))
                    ->orWhere(fn ($q) => $q->where('kind', 'prn')->whereRaw($prnHistoryTime.' >= ? AND '.$prnHistoryTime.' < ?', [$from, $to]));
            })->orderBy('scheduled_at')->orderBy('medication_id')->orderBy('id')->get()->all();
        foreach ($rows as $dose) {
            $this->reconcile($dose, $now);
        }

        return $rows;
    }

    public function resource(object $dose, User $actor, Time $now): array
    {
        $revision = DB::table('medication_schedule_revisions')->where('id', $dose->revision_id)->first();
        $prescription = json_decode($revision->prescription, true, 512, JSON_THROW_ON_ERROR);
        $status = $this->status($dose, $now);
        $actions = ['view'];
        $own = $actor->id === $dose->patient_id;
        if ($own && $dose->cancelled_at === null && $now >= Time::parse($revision->effective_from)) {
            if ($dose->kind === 'prn') {
                $actions[] = 'correct';
            } elseif (in_array($status, ['taken', 'skipped', 'missed'], true)) {
                $actions[] = 'correct';
                if ($status === 'missed') {
                    $actions[] = 'report_late_intake';
                }
            } elseif ($dose->local_date <= $now->setTimezone($dose->timezone)->format('Y-m-d')) {
                array_push($actions, 'taken', 'skipped');
            }
        }

        return ['id' => $dose->id, 'medication_id' => $dose->medication_id, 'revision_id' => $dose->revision_id,
            'medicine_name' => $prescription['name'], 'prescription' => $prescription, 'kind' => $dose->kind,
            'scheduled_at' => $dose->scheduled_at ? Time::parse($dose->scheduled_at)->utc()->toIso8601String() : null,
            'local_date' => $dose->local_date, 'local_time' => $dose->local_time ? substr($dose->local_time, 0, 5) : null,
            'timezone' => $dose->timezone, 'resolved_local_time' => $dose->resolved_local_time,
            'requested_local_times' => json_decode($dose->requested_local_times, true, 512, JSON_THROW_ON_ERROR),
            'status' => $status, 'missed_at' => $dose->missed_at ? Time::parse($dose->missed_at)->utc()->toIso8601String() : null,
            'actual_intake_at' => $dose->actual_intake_at ? Time::parse($dose->actual_intake_at)->utc()->toIso8601String() : null,
            'version' => $dose->version, 'can_report_late_intake' => $own && $status === 'missed', 'allowed_actions' => $actions];
    }

    private function details(object $dose, User $actor, Time $now): array
    {
        $this->reconcile($dose, $now);
        $events = DB::table('medication_intake_events')->where('occurrence_id', $dose->id)->orderBy('sequence')->get()
            ->map(fn ($event) => ['id' => $event->id, 'event_type' => $event->event_type, 'actor_type' => $event->actor_id === null ? 'system' : 'patient',
                'previous_resolution' => $event->previous_resolution, 'new_resolution' => $event->new_resolution,
                'actual_intake_at' => $event->actual_intake_at ? Time::parse($event->actual_intake_at)->utc()->toIso8601String() : null,
                'effective_at' => Time::parse($event->effective_at)->utc()->toIso8601String(), 'recorded_at' => Time::parse($event->recorded_at)->utc()->toIso8601String(), 'reason' => $event->reason])->all();

        return ['dose' => $this->resource($dose, $actor, $now), 'events' => $events];
    }

    private function dashboard(User $patient, User $actor, array $input, Time $now): array
    {
        [$from, $to] = $this->bounds($input, $now);
        $rows = $this->occurrences($patient->id, $from, $to, $now);
        $doses = array_map(fn ($dose) => $this->resource($dose, $actor, $now), $rows);
        $scheduled = array_values(array_filter($doses, fn ($d) => $d['kind'] === 'scheduled'));
        $missed = array_values(array_filter($scheduled, fn ($d) => $d['status'] === 'missed'));
        $due = array_values(array_filter($scheduled, fn ($d) => $d['status'] === 'overdue'));
        $future = array_values(array_filter($scheduled, fn ($d) => $d['status'] === 'pending'));
        $medications = DB::table('medications')->where('patient_id', $patient->id)->where(function ($q) use ($doses): void {
            $q->whereNull('archived_at')->orWhereIn('id', array_column($doses, 'medication_id'));
        })->orderBy('created_at')->orderBy('id')->get()->map(function ($med) use ($actor, $now, $doses): array {
            $items = array_values(array_filter($doses, fn ($d) => $d['medication_id'] === $med->id));
            $scheduled = array_values(array_filter($items, fn ($d) => $d['kind'] === 'scheduled'));

            return [...app(MedicationService::class)->resource($med, $actor, $now), 'doses' => $items,
                'taken_doses' => count(array_filter($scheduled, fn ($d) => $d['status'] === 'taken')), 'scheduled_doses' => count($scheduled)];
        })->all();
        [$cards, $cardsPage] = $this->page($medications, $input['page'] ?? 1, $input['per_page'] ?? 20);
        [$missedItems, $missedPage] = $this->page($missed, $input['missed_page'] ?? 1, $input['per_page'] ?? 20);

        return ['date' => $input['date'], 'timezone' => $input['timezone'], 'summary' => $this->summary($scheduled),
            'next_actionable_dose' => $due[0] ?? $future[0] ?? null, 'medications' => $cards,
            'medications_pagination' => $cardsPage, 'missed_doses' => $missedItems, 'missed_doses_pagination' => $missedPage];
    }

    private function summary(array $doses): array
    {
        $counts = array_count_values(array_column($doses, 'status'));
        $total = count($doses);
        $taken = $counts['taken'] ?? 0;

        return ['scheduled_doses' => $total, 'taken_doses' => $taken,
            'remaining_doses' => ($counts['pending'] ?? 0) + ($counts['overdue'] ?? 0), 'untaken_doses' => $total - $taken,
            'missed_doses' => $counts['missed'] ?? 0, 'skipped_doses' => $counts['skipped'] ?? 0,
            'progress_fraction' => $total === 0 ? 0.0 : $taken / $total];
    }

    private function listing(User $patient, User $actor, ?object $med, array $input, Time $now): array
    {
        [$from, $to] = $this->bounds($input, $now);
        $rows = $this->occurrences($patient->id, $from, $to, $now);
        $doses = array_values(array_map(fn ($d) => $this->resource($d, $actor, $now), array_filter($rows, fn ($d) => $med === null || $d->medication_id === $med->id)));
        [$items, $pagination] = $this->page($doses, $input['page'] ?? 1, $input['per_page'] ?? 20);

        return ['doses' => $items, 'pagination' => $pagination, 'timezone' => $input['timezone'],
            'summary' => $this->summary(array_values(array_filter($doses, fn ($d) => $d['kind'] === 'scheduled')))];
    }

    private function page(array $items, int $page, int $size): array
    {
        return [array_slice($items, ($page - 1) * $size, $size), ['page' => $page, 'per_page' => $size,
            'total' => count($items), 'last_page' => max(1, (int) ceil(count($items) / $size))]];
    }

    private function actualTime(object $dose, array $input, Time $now): Time
    {
        $actual = isset($input['actual_intake_at']) ? Time::parse($input['actual_intake_at'])->utc() : $now;
        if ($dose->kind === 'prn') {
            $revision = DB::table('medication_schedule_revisions')->where('id', $dose->revision_id)->first();
            $date = $actual->setTimezone($dose->timezone)->format('Y-m-d');
            if ($actual > $now || $actual < Time::parse($revision->effective_from)
                || ($revision->effective_to && $actual >= Time::parse($revision->effective_to))
                || $date < $revision->start_date || ($revision->end_date && $date > $revision->end_date)) {
                throw ValidationException::withMessages(['actual_intake_at' => 'PRN correction must remain within its original effective prescription and dates.']);
            }

            return $actual;
        }
        $earliest = app(Recurrence::class)->resolve($dose->local_date, '00:00', $dose->timezone);
        if ($actual > $now || $actual < $earliest) {
            throw ValidationException::withMessages(['actual_intake_at' => 'Actual intake must not be future or precede the scheduled local day.']);
        }

        return $actual;
    }

    private function action(string $op, object $dose, User $actor, array $input, Time $now): array
    {
        app(MedicationService::class)->version($dose, $input);
        if ($dose->cancelled_at !== null) {
            throw new MedicationFailure('dose-cancelled', 409);
        }
        $revision = DB::table('medication_schedule_revisions')->where('id', $dose->revision_id)->first();
        if ($now < Time::parse($revision->effective_from)) {
            throw new MedicationFailure('revision-not-effective', 409);
        }
        $status = $this->status($dose, $now);
        if ($op !== 'corrections') {
            if ($dose->kind !== 'scheduled' || in_array($status, ['taken', 'skipped'], true)) {
                throw new MedicationFailure('dose-already-resolved', 409);
            }
            if ($op !== 'late-intake' && $status === 'missed') {
                throw new MedicationFailure('late-intake-required', 409);
            }
            if ($op === 'late-intake' && $status !== 'missed') {
                throw new MedicationFailure('dose-not-missed', 409);
            }
            if ($dose->local_date > $now->setTimezone($dose->timezone)->format('Y-m-d')) {
                throw ValidationException::withMessages(['dose' => 'Intake may be recorded only on or after the scheduled local day.']);
            }
        }
        $target = $op === 'corrections' ? $input['target_status'] : ($op === 'skipped' ? 'skipped' : 'taken');
        if (($dose->kind === 'prn' && ! in_array($target, ['taken', 'void'], true)) || ($dose->kind === 'scheduled' && $target === 'void')) {
            throw ValidationException::withMessages(['target_status' => 'Unsupported correction for this occurrence type.']);
        }
        $this->reconcile($dose, $now);
        $actual = $target === 'taken' ? $this->actualTime($dose, $input, $now) : null;
        $resolution = $target === 'unresolved' ? null : $target;
        $eventType = $op === 'corrections' ? ($dose->kind === 'prn' ? ($target === 'void' ? 'prn_voided' : 'prn_corrected') : 'corrected') : $target;
        $this->event($dose, $actor, $eventType, $resolution, $actual, $input['reason'] ?? null, $now);
        $cancelledAt = null;
        if ($resolution === null) {
            $med = DB::table('medications')->where('id', $dose->medication_id)->first();
            if ($revision->superseded_at !== null
                || ($revision->effective_to && Time::parse($dose->scheduled_at) >= Time::parse($revision->effective_to))
                || ($med->archived_at && Time::parse($dose->scheduled_at) >= Time::parse($med->archived_at))) {
                $cancelledAt = $now;
            }
        }
        DB::table('medication_dose_occurrences')->where('id', $dose->id)->update(['resolution' => $resolution, 'actual_intake_at' => $actual,
            'local_date' => $dose->kind === 'prn' && $actual ? $actual->setTimezone($dose->timezone)->format('Y-m-d') : $dose->local_date,
            'cancelled_at' => $cancelledAt, 'version' => $dose->version + 1]);
        DB::table('medication_notification_candidates')->where('occurrence_id', $dose->id)->update(['state' => 'suppressed', 'reason' => 'intake-changed', 'evaluated_at' => $now]);
        $updated = DB::table('medication_dose_occurrences')->where('id', $dose->id)->first();
        $this->reconcile($updated, $now);

        return $this->resource($updated, $actor, $now);
    }

    private function event(object $dose, User $actor, string $type, ?string $resolution, ?Time $actual, ?string $reason, Time $now): void
    {
        DB::table('medication_intake_events')->insert(['id' => (string) Str::uuid(), 'occurrence_id' => $dose->id,
            'medication_id' => $dose->medication_id, 'patient_id' => $dose->patient_id, 'actor_id' => $actor->id,
            'event_type' => $type, 'previous_resolution' => $dose->resolution, 'new_resolution' => $resolution,
            'actual_intake_at' => $actual, 'effective_at' => $now, 'recorded_at' => $now, 'reason' => $reason]);
        app(MedicationService::class)->audit($dose->patient_id, $dose->medication_id, $actor->id, 'intake-'.$type, ['occurrence_id' => $dose->id, 'previous_resolution' => $dose->resolution, 'resolution' => $resolution], $now);
    }

    private function prn(object $med, User $actor, array $input, Time $now): array
    {
        $service = app(MedicationService::class);
        $service->version($med, $input);
        $service->active($med);
        $actual = isset($input['actual_intake_at']) ? Time::parse($input['actual_intake_at'])->utc() : $now;
        $revision = DB::table('medication_schedule_revisions')->where('medication_id', $med->id)->whereNull('superseded_at')->where('effective_from', '<=', $actual)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $actual))->first();
        if (! $revision || $revision->frequency !== 'as_needed' || $actual > $now) {
            throw ValidationException::withMessages(['actual_intake_at' => 'An actual nonfuture intake within an effective PRN prescription is required.']);
        }
        $local = $actual->setTimezone($revision->timezone)->format('Y-m-d');
        if ($local < $revision->start_date || ($revision->end_date && $local > $revision->end_date)) {
            throw ValidationException::withMessages(['actual_intake_at' => 'Intake is outside prescription dates.']);
        }
        $id = (string) Str::uuid();
        DB::table('medication_dose_occurrences')->insert(['id' => $id, 'medication_id' => $med->id, 'patient_id' => $med->patient_id,
            'revision_id' => $revision->id, 'kind' => 'prn', 'local_date' => $local, 'timezone' => $revision->timezone,
            'resolution' => 'taken', 'actual_intake_at' => $actual, 'created_at' => $now]);
        $dose = DB::table('medication_dose_occurrences')->where('id', $id)->first();
        $before = clone $dose;
        $before->resolution = null;
        $this->event($before, $actor, 'prn_taken', 'taken', $actual, null, $now);

        return $this->resource($dose, $actor, $now);
    }
}
