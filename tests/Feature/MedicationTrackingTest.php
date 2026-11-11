<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use App\Application\Medication\MedicationEvaluator;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Support\MedicationTestCase;

class MedicationTrackingTest extends MedicationTestCase
{
    private function dashboard(): array
    {
        return $this->getJson($this->root.'/medication-dashboard?date=2026-11-11&timezone=UTC')->assertOk()->json('data');
    }

    private function occurrence(): array
    {
        return $this->getJson($this->root.'/medication-doses?from=2026-11-11&to=2026-11-11&timezone=UTC')->assertOk()->json('data.doses.0');
    }

    #[DataProvider('boundaries')]
    public function test_authoritative_missed_boundaries_without_scheduler(string $clock, string $expected): void
    {
        $this->createMedication();
        Time::setTestNow(Time::parse('2026-11-11T'.$clock.'Z'));
        $dose = $this->occurrence();
        $this->assertSame($expected, $dose['status']);
        $this->assertSame($expected === 'missed' ? 1 : 0, DB::table('medication_intake_events')->where('event_type', 'missed')->count());
    }

    public static function boundaries(): array
    {
        return [['07:59:59', 'pending'], ['08:00:00', 'overdue'], ['08:00:01', 'overdue'],
            ['08:29:59', 'overdue'], ['08:30:00', 'missed'], ['10:00:00', 'missed']];
    }

    public function test_early_actual_intake_duplicate_action_and_future_timestamp(): void
    {
        $this->createMedication();
        $dose = $this->occurrence();
        $url = $this->root.'/medication-doses/'.$dose['id'].'/taken';
        $this->mutation('POST', $url, ['expected_version' => 1, 'actual_intake_at' => '2026-11-11T06:00:01+00:00'])->assertUnprocessable();
        $this->mutation('POST', $url, ['expected_version' => 1, 'actual_intake_at' => '2026-11-10T23:59:59+00:00'])->assertUnprocessable();
        $key = (string) Str::uuid();
        $input = ['expected_version' => 1, 'actual_intake_at' => '2026-11-11T05:59:00+00:00'];
        $this->mutation('POST', $url, $input, $key)->assertOk()->assertJsonPath('data.dose.status', 'taken');
        $this->mutation('POST', $url, $input, $key)->assertOk();
        $this->assertSame(1, DB::table('medication_intake_events')->where('event_type', 'taken')->count());
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/skipped', ['expected_version' => 1])->assertConflict();
        $this->assertSame('2026-11-11 08:00:00+00', DB::table('medication_dose_occurrences')->value('scheduled_at'));
    }

    public function test_late_reporting_earlier_actual_intake_preserves_missed_history_and_corrections(): void
    {
        $this->createMedication();
        Time::setTestNow(Time::parse('2026-11-11T10:00:00Z'));
        $dose = $this->occurrence();
        $url = $this->root.'/medication-doses/'.$dose['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.dose.status', 'missed');
        $this->assertSame(1, DB::table('medication_intake_events')->count());
        $this->mutation('POST', $url.'/taken', ['expected_version' => 1])->assertConflict()->assertJsonPath('code', 'late-intake-required');
        $this->mutation('POST', $url.'/late-intake', ['expected_version' => 1, 'actual_intake_at' => '2026-11-11T08:05:00+00:00'])
            ->assertOk()->assertJsonPath('data.dose.status', 'taken')->assertJsonPath('data.dose.actual_intake_at', '2026-11-11T08:05:00+00:00');
        $this->mutation('POST', $url.'/corrections', ['expected_version' => 2, 'target_status' => 'skipped', 'reason' => 'Recorded incorrectly'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.dose.status', 'skipped')->assertJsonCount(3, 'data.events');
        $this->assertSame(1, DB::table('medication_intake_events')->where('event_type', 'missed')->count());
        $missed = DB::table('medication_intake_events')->where('event_type', 'missed')->first();
        $this->assertSame('2026-11-11 08:30:00+00', $missed->effective_at);
        $this->assertSame('2026-11-11 10:00:00+00', $missed->recorded_at);
    }

    public function test_resolved_before_deadline_does_not_gain_false_missed_event_on_later_correction(): void
    {
        $this->createMedication();
        $dose = $this->occurrence();
        $url = $this->root.'/medication-doses/'.$dose['id'];
        $this->mutation('POST', $url.'/taken', ['expected_version' => 1])->assertOk();
        Time::setTestNow(Time::parse('2026-11-11T10:00:00Z'));
        $this->mutation('POST', $url.'/corrections', ['expected_version' => 2, 'target_status' => 'skipped', 'reason' => 'Correction'])->assertOk();
        $this->assertSame(0, DB::table('medication_intake_events')->where('event_type', 'missed')->count());
    }

    public function test_event_history_preserves_causal_order_with_identical_recorded_times(): void
    {
        $this->createMedication();
        $dose = $this->occurrence();
        Time::setTestNow(Time::parse('2026-11-11T10:00:00Z'));
        $ids = ['ffffffff-ffff-4fff-afff-ffffffffffff', '00000000-0000-4000-a000-000000000001', '11111111-1111-4111-a111-111111111111'];
        $index = 0;
        Str::createUuidsUsing(function () use (&$index, $ids) {
            return Uuid::fromString($ids[$index++]);
        });
        try {
            $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/late-intake', ['expected_version' => 1], '22222222-2222-4222-a222-222222222222')->assertOk();
        } finally {
            Str::createUuidsNormally();
        }
        $events = $this->getJson($this->root.'/medication-doses/'.$dose['id'])->assertOk()->json('data.events');
        $this->assertSame(['missed', 'taken'], array_column($events, 'event_type'));
    }

    public function test_dashboard_due_priority_separate_missed_and_global_counts_with_pagination(): void
    {
        $this->createMedication(['frequency' => 'three_times_daily', 'times' => ['07:00', '08:00', '09:00']]);
        Time::setTestNow(Time::parse('2026-11-11T08:10:00Z'));
        $data = $this->dashboard();
        $this->assertSame('08:00', $data['next_actionable_dose']['local_time']);
        $this->assertCount(1, $data['missed_doses']);
        $this->assertSame('missed', $data['missed_doses'][0]['status']);
        $this->assertTrue($data['missed_doses'][0]['can_report_late_intake']);
        $this->assertSame(3, $data['summary']['scheduled_doses']);
        $this->assertSame(2, $data['summary']['remaining_doses']);
        $due = $data['next_actionable_dose'];
        $this->mutation('POST', $this->root.'/medication-doses/'.$due['id'].'/taken', ['expected_version' => 1])->assertOk();
        $this->assertSame('09:00', $this->dashboard()['next_actionable_dose']['local_time']);
        $data = $this->getJson($this->root.'/medication-dashboard?date=2026-11-11&timezone=UTC&per_page=1&page=2')->assertOk()->json('data');
        $this->assertSame(3, $data['summary']['scheduled_doses']);
        $this->assertEqualsWithDelta(1 / 3, $data['summary']['progress_fraction'], 0.000001);
        Time::setTestNow(Time::parse('2026-11-11T12:00:00Z'));
        $this->assertNull($this->dashboard()['next_actionable_dose']);
    }

    public function test_caregiver_can_read_but_cannot_mutate_intake_or_prn(): void
    {
        $this->createMedication();
        $dose = $this->occurrence();
        Sanctum::actingAs($this->carer);
        $this->assertSame([], array_values(array_intersect(['taken', 'skipped', 'correct'], $this->dashboard()['medications'][0]['doses'][0]['allowed_actions'])));
        foreach (['taken', 'skipped', 'late-intake'] as $action) {
            $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/'.$action, ['expected_version' => 1])->assertForbidden();
        }
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/corrections', ['expected_version' => 1, 'target_status' => 'skipped', 'reason' => 'No'])->assertForbidden();
    }

    public function test_prn_manual_intake_is_not_scheduled_or_missed_and_can_be_voided(): void
    {
        $med = $this->createMedication(['frequency' => 'as_needed', 'times' => []]);
        $this->assertSame(0, $this->dashboard()['summary']['scheduled_doses']);
        $dose = $this->mutation('POST', $this->root.'/medications/'.$med['id'].'/prn-intakes', ['expected_version' => 1])->assertCreated()->json('data.dose');
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/corrections', ['expected_version' => 1, 'target_status' => 'unresolved', 'reason' => 'No'])->assertUnprocessable();
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/corrections', ['expected_version' => 1, 'target_status' => 'void', 'reason' => 'Mistake'])->assertOk();
        $this->getJson($this->root.'/medications/'.$med['id'].'/adherence?from=2026-11-11&to=2026-11-11&timezone=UTC')
            ->assertOk()->assertJsonPath('data.doses.0.status', 'void');
        Time::setTestNow(Time::parse('2026-11-11T23:00:00Z'));
        $this->assertSame(0, $this->dashboard()['summary']['missed_doses']);
        $this->assertSame(0, DB::table('medication_intake_events')->where('event_type', 'missed')->count());
    }

    public function test_archive_and_revision_cancel_future_only_without_losing_unmaterialized_history(): void
    {
        $med = $this->createMedication(['frequency' => 'twice_daily', 'times' => ['08:00', '20:00']]);
        $this->getJson($this->root.'/medication-doses?from=2026-11-12&to=2026-11-12&timezone=UTC')->assertOk()->assertJsonCount(2, 'data.doses');
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'], ['expected_version' => 1, 'schedule' => $this->payload(['times' => ['10:00']])['schedule']])->assertOk();
        $this->getJson($this->root.'/medication-doses?from=2026-11-12&to=2026-11-12&timezone=UTC')->assertOk()->assertJsonCount(1, 'data.doses');
        Time::setTestNow(Time::parse('2026-11-11T12:00:00Z'));
        $this->mutation('POST', $this->root.'/medications/'.$med['id'].'/archive', ['expected_version' => 2])->assertOk();
        $data = $this->dashboard();
        $this->assertSame(1, $data['summary']['missed_doses']);
        $this->assertSame(1, $data['summary']['scheduled_doses']);
        $this->assertNull($data['next_actionable_dose']);
    }

    public function test_cross_timezone_display_does_not_change_schedule_or_early_intake_day(): void
    {
        $this->createMedication(['timezone' => 'Asia/Tokyo', 'times' => ['23:00']]);
        $data = $this->getJson($this->root.'/medication-dashboard?date=2026-11-11&timezone=America%2FNew_York')->assertOk()->json('data');
        $dose = $data['next_actionable_dose'];
        $this->assertSame('2026-11-11T14:00:00+00:00', $dose['scheduled_at']);
        $this->assertSame('Asia/Tokyo', $dose['timezone']);
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/taken', ['expected_version' => 1, 'actual_intake_at' => '2026-11-10T14:59:59+00:00'])->assertUnprocessable();
    }

    public function test_escalation_requires_patient_consent_and_active_link_and_never_dispatches(): void
    {
        $payload = $this->payload();
        $payload['reminder_preferences'] = ['notify_caregiver_if_missed' => true];
        $this->mutation('POST', $this->root.'/medications', $payload)->assertCreated();
        Time::setTestNow(Time::parse('2026-11-11T08:59:59Z'));
        app(MedicationEvaluator::class)->evaluatePatient($this->patient->id, Time::now('UTC'));
        $this->assertSame(0, DB::table('medication_notification_candidates')->where('candidate_type', 'escalation')->count());
        Time::setTestNow(Time::parse('2026-11-11T09:00:00Z'));
        app(MedicationEvaluator::class)->evaluatePatient($this->patient->id, Time::now('UTC'));
        app(MedicationEvaluator::class)->evaluatePatient($this->patient->id, Time::now('UTC'));
        $this->assertSame(1, DB::table('medication_notification_candidates')->where('candidate_type', 'escalation')->where('state', 'eligible')->count());
        app(CaregiverLinks::class)->transition($this->patient, DB::table('caregiver_links')->value('id'), 'revoke');
        app(MedicationEvaluator::class)->evaluatePatient($this->patient->id, Time::now('UTC'));
        $this->assertSame(0, DB::table('medication_notification_candidates')->where('state', 'eligible')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertFalse(config('medication.scheduler_enabled'));
    }

    public function test_ranges_are_bounded_and_empty_dashboard_is_explicit(): void
    {
        $this->assertNull($this->dashboard()['next_actionable_dose']);
        $this->assertSame([], $this->dashboard()['missed_doses']);
        $this->getJson($this->root.'/medication-doses?from=2026-01-01&to=2026-11-11&timezone=UTC')->assertUnprocessable();
        $this->getJson($this->root.'/medication-dashboard?date=2027-01-01&timezone=UTC')->assertUnprocessable();
    }

    public function test_timezone_shift_does_not_allow_intake_from_a_pending_revision(): void
    {
        $med = $this->createMedication();
        Time::setTestNow(Time::parse('2026-11-11T23:00:00Z'));
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'], ['expected_version' => 1,
            'schedule' => $this->payload(['timezone' => 'Asia/Tokyo', 'times' => ['09:00']])['schedule']])->assertOk();
        $dose = $this->getJson($this->root.'/medication-doses?from=2026-11-12&to=2026-11-12&timezone=Asia%2FTokyo')->assertOk()->json('data.doses.0');
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/taken', ['expected_version' => 1])->assertConflict();
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/corrections', ['expected_version' => 1,
            'target_status' => 'taken', 'reason' => 'Attempt to bypass activation'])->assertConflict();
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'], ['expected_version' => 2,
            'schedule' => $this->payload(['timezone' => 'Asia/Tokyo', 'times' => ['10:00']])['schedule']])->assertOk();
        $this->getJson($this->root.'/medication-doses?from=2026-11-12&to=2026-11-12&timezone=Asia%2FTokyo')->assertOk()->assertJsonCount(1, 'data.doses');
    }

    public function test_reset_of_early_taken_archived_future_dose_cancels_instead_of_resurrecting(): void
    {
        $med = $this->createMedication();
        $dose = $this->occurrence();
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/taken', ['expected_version' => 1])->assertOk();
        Time::setTestNow(Time::parse('2026-11-11T07:00:00Z'));
        $this->mutation('POST', $this->root.'/medications/'.$med['id'].'/archive', ['expected_version' => 1])->assertOk();
        $this->mutation('POST', $this->root.'/medication-doses/'.$dose['id'].'/corrections', ['expected_version' => 2,
            'target_status' => 'unresolved', 'reason' => 'Mistaken early intake'])->assertOk()->assertJsonPath('data.dose.status', 'cancelled');
        $this->assertNull($this->dashboard()['next_actionable_dose']);
        $this->assertSame(0, $this->dashboard()['summary']['scheduled_doses']);
    }
}
