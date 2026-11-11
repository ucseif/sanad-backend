<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use App\Application\Medication\MedicationEvaluator;
use App\Enums\UserRole;
use Carbon\CarbonImmutable as Time;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CaregiverLinkTables;
use Tests\Support\MedicationTestCase;

class MedicationMaintenanceTest extends MedicationTestCase
{
    public function test_command_is_bounded_idempotent_and_schedule_disabled(): void
    {
        $this->createMedication();
        Time::setTestNow(Time::parse('2026-11-11T10:00:00Z'));
        $this->artisan('medication:evaluate', ['--patient' => $this->patient->id])->assertSuccessful();
        $this->artisan('medication:evaluate', ['--patient' => $this->patient->id])->assertSuccessful();
        // Catch-up is bounded to seven-day chunks; a second run can fill the final partial chunk.
        $count = DB::table('medication_dose_occurrences')->count();
        $this->artisan('medication:evaluate', ['--patient' => $this->patient->id])->assertSuccessful();
        $this->assertSame($count, DB::table('medication_dose_occurrences')->count());
        $this->assertSame(1, DB::table('medication_intake_events')->where('event_type', 'missed')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->artisan('medication:evaluate', ['--limit' => 101])->assertFailed();
        $events = app(Schedule::class)->events();
        $this->assertCount(0, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'medication:evaluate')));
    }

    public function test_other_active_caregiver_keeps_the_shared_record_after_creator_revoked(): void
    {
        $second = CaregiverLinkTables::user('Caregiver2', UserRole::Caregiver);
        $link = app(CaregiverLinks::class)->invite($this->patient, $second->username);
        app(CaregiverLinks::class)->transition($second, $link->id, 'accept');
        Sanctum::actingAs($this->carer);
        $med = $this->createMedication();
        app(CaregiverLinks::class)->transition($this->patient, DB::table('caregiver_links')->where('caregiver_id', $this->carer->id)->value('id'), 'revoke');
        Sanctum::actingAs($second);
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'], ['expected_version' => 1, 'prescription' => ['name' => 'Shared change']])->assertOk();
        Sanctum::actingAs($this->patient);
        $this->getJson($this->root.'/medications/'.$med['id'])->assertOk()->assertJsonPath('data.medication.pending_revision.prescription.name', 'Shared change');
    }

    public function test_scheduled_cycle_advances_and_wraps_without_starving_other_patients(): void
    {
        $this->createMedication();
        Sanctum::actingAs($this->other);
        $this->mutation('POST', '/api/v1/patients/'.$this->other->id.'/medications', $this->payload())->assertCreated();
        Cache::forget('medication:evaluate:cursor');
        $this->artisan('medication:evaluate', ['--cycle' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame($this->patient->id, Cache::get('medication:evaluate:cursor'));
        $this->artisan('medication:evaluate', ['--cycle' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame($this->other->id, Cache::get('medication:evaluate:cursor'));
        $this->artisan('medication:evaluate', ['--cycle' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame($this->patient->id, Cache::get('medication:evaluate:cursor'));
    }

    public function test_late_opt_in_does_not_escalate_historical_missed_dose(): void
    {
        $med = $this->createMedication();
        Time::setTestNow(Time::parse('2026-11-11T09:00:00Z'));
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'].'/escalation-consent', ['expected_version' => 1, 'notify_caregiver_if_missed' => true])->assertOk();
        app(MedicationEvaluator::class)->evaluatePatient($this->patient->id, Time::now('UTC'));
        $this->assertSame(0, DB::table('medication_notification_candidates')->where('candidate_type', 'escalation')->count());
    }

    public function test_prn_timestamp_correction_must_stay_in_effective_prescription_and_updates_local_day(): void
    {
        $med = $this->createMedication(['frequency' => 'as_needed', 'times' => [], 'end_date' => '2026-11-12']);
        Time::setTestNow(Time::parse('2026-11-14T06:00:00Z'));
        $dose = $this->mutation('POST', $this->root.'/medications/'.$med['id'].'/prn-intakes', ['expected_version' => 1, 'actual_intake_at' => '2026-11-11T10:00:00+00:00'])->assertCreated()->json('data.dose');
        $url = $this->root.'/medication-doses/'.$dose['id'].'/corrections';
        $this->mutation('POST', $url, ['expected_version' => 1, 'target_status' => 'taken', 'actual_intake_at' => '2026-11-13T10:00:00+00:00', 'reason' => 'Invalid date'])->assertUnprocessable();
        $this->mutation('POST', $url, ['expected_version' => 1, 'target_status' => 'taken', 'actual_intake_at' => '2026-11-12T10:00:00+00:00', 'reason' => 'Correct date'])
            ->assertOk()->assertJsonPath('data.dose.local_date', '2026-11-12');
    }
}
