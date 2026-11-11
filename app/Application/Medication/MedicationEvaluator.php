<?php

namespace App\Application\Medication;

use App\Models\User;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reconciles durable tracking and eligibility only. No transport or notification dispatch. */
final class MedicationEvaluator
{
    public function evaluatePatient(int $patient, Time $now): array
    {
        return DB::transaction(function () use ($patient, $now): array {
            User::query()->whereKey($patient)->lockForUpdate()->firstOrFail();
            $generated = app(DoseMaterializer::class)->catchUp($patient, $now);
            $evaluated = 0;
            DB::table('medication_dose_occurrences')->where('patient_id', $patient)->where('kind', 'scheduled')->where('scheduled_at', '<=', $now)
                ->orderBy('id')->chunkById(200, function ($rows) use ($now, &$evaluated): void {
                    foreach ($rows as $dose) {
                        app(MedicationTracking::class)->reconcile($dose, $now);
                        $this->candidates($dose, $now);
                        $evaluated++;
                    }
                });

            return ['generated' => $generated, 'evaluated' => $evaluated, 'delivered' => 0];
        }, 3);
    }

    private function candidates(object $dose, Time $now): void
    {
        $status = app(MedicationTracking::class)->status($dose, $now);
        $prefs = DB::table('medication_preferences')->where('medication_id', $dose->medication_id)->first();
        $med = DB::table('medications')->where('id', $dose->medication_id)->first();
        // Existing candidates are never evidence of continuing permission.
        DB::table('medication_notification_candidates')->where('occurrence_id', $dose->id)->update(['state' => 'suppressed', 'reason' => 'no-longer-eligible', 'evaluated_at' => $now]);
        if ($med->archived_at || $dose->cancelled_at || in_array($status, ['taken', 'skipped'], true)) {
            return;
        }
        if ($prefs->enable_medication_reminders && $status === 'overdue') {
            $this->candidate($dose, $dose->patient_id, null, 'reminder', Time::parse($dose->scheduled_at), $prefs->consent_version, $now);
        }
        $eligible = Time::parse($dose->missed_at)->addMinutes($prefs->escalation_delay_minutes);
        if ($status !== 'missed' || ! $prefs->notify_caregiver_if_missed || $eligible > $now || Time::parse($prefs->consent_at) > Time::parse($dose->missed_at)) {
            return;
        }
        $links = DB::table('caregiver_links')->where('patient_id', $dose->patient_id)->where('status', 'active')->where('accepted_at', '<=', $dose->missed_at)->lockForUpdate()->get();
        foreach ($links as $link) {
            $this->candidate($dose, $link->caregiver_id, $link->id, 'escalation', $eligible, $prefs->consent_version, $now);
        }
    }

    private function candidate(object $dose, int $recipient, ?string $link, string $type, Time $eligible, int $consentVersion, Time $now): void
    {
        $query = DB::table('medication_notification_candidates')->where('occurrence_id', $dose->id)->where('candidate_type', $type)->where('recipient_id', $recipient)->where('relationship_id', $link);
        $existing = $query->first();
        // A withdrawn/replaced consent cannot revive an older candidate.
        if ($existing && $type === 'escalation' && $existing->consent_version !== $consentVersion) {
            return;
        }
        $values = ['eligible_at' => $eligible, 'consent_version' => $consentVersion, 'state' => 'eligible', 'reason' => null, 'evaluated_at' => $now];
        if ($existing) {
            $query->update($values);
        } else {
            DB::table('medication_notification_candidates')->insert([...$values, 'id' => (string) Str::uuid(), 'occurrence_id' => $dose->id,
                'medication_id' => $dose->medication_id, 'patient_id' => $dose->patient_id, 'candidate_type' => $type,
                'recipient_id' => $recipient, 'relationship_id' => $link, 'created_at' => $now]);
        }
    }
}
