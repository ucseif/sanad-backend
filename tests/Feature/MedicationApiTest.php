<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MedicationTestCase;

class MedicationApiTest extends MedicationTestCase
{
    public function test_patient_timezone_bootstrap_is_explicit_and_used_only_for_new_schedules(): void
    {
        $this->getJson($this->root.'/medication-settings')->assertOk()->assertJsonPath('data.settings.confirmed_timezone', null);
        $payload = $this->payload();
        unset($payload['schedule']['timezone']);
        $this->mutation('POST', $this->root.'/medications', $payload)->assertUnprocessable();
        $this->mutation('PATCH', $this->root.'/medication-settings', ['timezone' => 'Asia/Tokyo', 'expected_version' => 0])->assertOk();
        $this->mutation('POST', $this->root.'/medications', $payload)->assertCreated()->assertJsonPath('data.medication.current_revision.schedule.timezone', 'Asia/Tokyo');
        $this->mutation('PATCH', $this->root.'/medication-settings', ['timezone' => 'Europe/London', 'expected_version' => 1])->assertOk();
        $this->assertSame('Asia/Tokyo', DB::table('medication_schedule_revisions')->value('timezone'));
        Sanctum::actingAs($this->carer);
        $this->mutation('PATCH', $this->root.'/medication-settings', ['timezone' => 'UTC', 'expected_version' => 2])->assertForbidden();
    }

    public function test_caregiver_creates_patient_owned_shared_record_and_revocation_preserves_it(): void
    {
        Sanctum::actingAs($this->carer);
        $med = $this->createMedication();
        $this->assertSame($this->patient->id, DB::table('medications')->value('patient_id'));
        $this->assertSame($this->carer->id, DB::table('medications')->value('created_by'));
        Sanctum::actingAs($this->patient);
        $this->getJson($this->root.'/medications')->assertOk()->assertJsonPath('data.medications.0.id', $med['id']);
        app(CaregiverLinks::class)->transition($this->patient, DB::table('caregiver_links')->value('id'), 'revoke');
        Sanctum::actingAs($this->carer);
        $this->getJson($this->root.'/medications/'.$med['id'])->assertNotFound();
        Sanctum::actingAs($this->patient);
        $this->getJson($this->root.'/medications/'.$med['id'])->assertOk()->assertJsonPath('data.medication.archived_at', null);
    }

    public function test_idempotency_optimistic_edits_and_pending_revision_replacement(): void
    {
        $key = (string) Str::uuid();
        $first = $this->mutation('POST', $this->root.'/medications', $this->payload(), $key)->assertCreated()->json();
        $this->assertEquals($first, $this->mutation('POST', $this->root.'/medications', $this->payload(), $key)->assertCreated()->json());
        $changed = $this->payload();
        $changed['prescription']['name'] = 'Different';
        $this->mutation('POST', $this->root.'/medications', $changed, $key)->assertConflict();
        $id = $first['data']['medication']['id'];
        $url = $this->root.'/medications/'.$id;
        $this->mutation('PATCH', $url, ['expected_version' => 1, 'schedule' => $this->payload(['times' => ['09:00']])['schedule']])
            ->assertOk()->assertJsonPath('data.medication.pending_revision.effective_from', '2026-11-12T00:00:00+00:00');
        $this->mutation('PATCH', $url, ['expected_version' => 1, 'prescription' => ['name' => 'stale']])->assertConflict();
        $this->mutation('PATCH', $url, ['expected_version' => 2, 'prescription' => ['name' => 'Updated']])->assertOk();
        $this->assertSame(3, DB::table('medication_schedule_revisions')->count());
        $this->assertSame(1, DB::table('medication_schedule_revisions')->whereNotNull('superseded_at')->count());
        Time::setTestNow(Time::parse('2026-11-12T06:00:00Z'));
        $this->getJson($url)->assertOk()->assertJsonPath('data.medication.current_revision.prescription.name', 'Updated');
    }

    public function test_caregiver_cannot_set_or_override_patient_consent(): void
    {
        Sanctum::actingAs($this->carer);
        $payload = $this->payload();
        $payload['reminder_preferences'] = ['notify_caregiver_if_missed' => true];
        $this->mutation('POST', $this->root.'/medications', $payload)->assertForbidden();
        $med = $this->createMedication();
        $this->assertFalse($med['reminder_preferences']['notify_caregiver_if_missed']);
        Sanctum::actingAs($this->patient);
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'].'/escalation-consent',
            ['expected_version' => 1, 'notify_caregiver_if_missed' => true, 'escalation_delay_minutes' => 30])->assertOk();
        Sanctum::actingAs($this->carer);
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'].'/escalation-consent',
            ['expected_version' => 2, 'notify_caregiver_if_missed' => false])->assertForbidden();
        $this->mutation('PATCH', $this->root.'/medications/'.$med['id'], ['expected_version' => 1, 'prescription' => ['name' => 'Changed']])->assertOk();
        $this->assertTrue(DB::table('medication_preferences')->value('notify_caregiver_if_missed'));
    }

    public function test_other_patient_and_pending_link_cannot_access_or_leak_resources(): void
    {
        $med = $this->createMedication();
        Sanctum::actingAs($this->other);
        $this->getJson($this->root.'/medications/'.$med['id'])->assertNotFound();
        $this->mutation('POST', $this->root.'/medications', $this->payload())->assertNotFound();
        DB::table('caregiver_links')->update(['status' => 'pending', 'accepted_at' => null]);
        Sanctum::actingAs($this->carer);
        $this->getJson($this->root.'/medications')->assertNotFound();
    }

    public function test_archiving_retains_history_and_does_not_delete_relationship(): void
    {
        $med = $this->createMedication();
        Sanctum::actingAs($this->carer);
        $this->mutation('POST', $this->root.'/medications/'.$med['id'].'/archive', ['expected_version' => 1])->assertOk();
        $this->getJson($this->root.'/medications')->assertJsonCount(0, 'data.medications');
        $this->getJson($this->root.'/medications?status=archived')->assertJsonCount(1, 'data.medications');
        $this->getJson($this->root.'/medications/'.$med['id'].'/schedule-revisions')->assertOk()->assertJsonCount(1, 'data.revisions');
        $this->assertSame(1, DB::table('caregiver_links')->where('status', 'active')->count());
    }

    #[DataProvider('invalidSchedules')]
    public function test_strict_frequency_validation(array $changes): void
    {
        $this->mutation('POST', $this->root.'/medications', $this->payload($changes))->assertUnprocessable();
    }

    public static function invalidSchedules(): array
    {
        return [[['times' => []]], [['frequency' => 'twice_daily', 'times' => ['08:00', '08:00']]],
            [['frequency' => 'three_times_daily', 'times' => ['08:00']]], [['frequency' => 'specific_days', 'weekdays' => []]],
            [['frequency' => 'every_hours', 'times' => [], 'interval_hours' => 0, 'anchor_time' => '08:00']],
            [['frequency' => 'as_needed', 'times' => ['08:00']]], [['timezone' => 'Egypt/Cairo']],
            [['end_date' => '2026-11-10']], [['created_by' => 123]], [['times' => ['25:00']]]];
    }

    public function test_authentication_unknown_fields_pagination_and_private_envelope(): void
    {
        $payload = $this->payload();
        $payload['patient_id'] = $this->other->id;
        $this->mutation('POST', $this->root.'/medications', $payload)->assertUnprocessable();
        $this->getJson($this->root.'/medications?per_page=101')->assertUnprocessable();
        $this->getJson($this->root.'/medications')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->app['auth']->forgetGuards();
        $this->getJson($this->root.'/medications')->assertUnauthorized();
    }
}
