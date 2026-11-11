<?php

namespace App\Application\Medication;

use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DoseMaterializer
{
    /** Caller holds the patient lock. On-demand reads do not advance background cursors. */
    public function between(int $patient, Time $from, Time $to, Time $now): int
    {
        $count = 0;
        $rows = DB::table('medication_schedule_revisions as r')->join('medications as m', 'm.id', '=', 'r.medication_id')
            ->where('r.patient_id', $patient)->whereNull('r.superseded_at')->where('r.effective_from', '<', $to)
            ->where(fn ($q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>', $from))
            ->select('r.*', 'm.archived_at')->orderBy('r.medication_id')->orderBy('r.effective_from')->get();
        foreach ($rows as $row) {
            $end = $row->archived_at ? $to->min(Time::parse($row->archived_at)) : $to;
            $revision = app(MedicationService::class)->revision($row);
            $schedule = [...$revision['schedule'], 'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to, 'anchor_at' => $row->anchor_at];
            foreach (app(Recurrence::class)->between($schedule, $from, $end) as $item) {
                $at = Time::parse($item['scheduled_at']);
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'sanad:medication:'.$row->id.':'.$at->getTimestamp())->toString();
                $count += DB::table('medication_dose_occurrences')->insertOrIgnore(['id' => $id,
                    'patient_id' => $patient, 'medication_id' => $row->medication_id, 'revision_id' => $row->id, 'kind' => 'scheduled',
                    'scheduled_at' => $at, 'missed_at' => $at->addSeconds($row->grace_seconds),
                    'local_date' => $item['local_date'], 'local_time' => $item['local_time'], 'timezone' => $row->timezone,
                    'resolved_local_time' => $item['resolved_local_time'], 'requested_local_times' => json_encode($item['requested_local_times'], JSON_THROW_ON_ERROR), 'created_at' => $now]);
            }
        }

        return $count;
    }

    public function catchUp(int $patient, Time $now): int
    {
        $count = 0;
        $rows = DB::table('medication_schedule_revisions as r')->join('medications as m', 'm.id', '=', 'r.medication_id')
            ->where('r.patient_id', $patient)->whereNull('r.superseded_at')->select('r.*', 'm.archived_at')->orderBy('r.effective_from')->get();
        foreach ($rows as $row) {
            $from = Time::parse($row->generated_through ?? $row->effective_from);
            $to = $from->addDays(7)->min($now->addDays(7));
            foreach ([$row->effective_to, $row->archived_at] as $cutoff) {
                if ($cutoff) {
                    $to = $to->min(Time::parse($cutoff));
                }
            }
            if ($from >= $to) {
                continue;
            }
            $count += $this->between($patient, $from, $to, $now);
            DB::table('medication_schedule_revisions')->where('id', $row->id)->update(['generated_through' => $to]);
        }

        return $count;
    }
}
