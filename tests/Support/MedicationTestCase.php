<?php

namespace Tests\Support;

use App\Application\Caregiving\CaregiverLinks;
use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class MedicationTestCase extends TestCase
{
    protected User $patient;

    protected User $carer;

    protected User $other;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        MedicationTables::create();
        Time::setTestNow(Time::parse('2026-11-11T06:00:00Z'));
        $this->patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $this->carer = CaregiverLinkTables::user('Caregiver1', UserRole::Caregiver);
        $this->other = CaregiverLinkTables::user('OtherPatient', UserRole::Patient);
        $link = app(CaregiverLinks::class)->invite($this->patient, $this->carer->username);
        app(CaregiverLinks::class)->transition($this->carer, $link->id, 'accept');
        $this->root = '/api/v1/patients/'.$this->patient->id;
        Sanctum::actingAs($this->patient);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }

    protected function payload(array $schedule = []): array
    {
        return ['prescription' => ['name' => 'Example medication', 'dose_amount' => '1', 'dose_unit' => 'tablet',
            'form' => 'tablet', 'relation_to_meals' => 'no_preference', 'instructions' => '', 'special_notes' => ''],
            'schedule' => array_replace(['frequency' => 'once_daily', 'timezone' => 'UTC', 'start_date' => '2026-11-11',
                'end_date' => null, 'times' => ['08:00'], 'weekdays' => [], 'interval_hours' => null, 'anchor_time' => null], $schedule)];
    }

    protected function mutation(string $method, string $url, array $data, ?string $key = null)
    {
        return $this->json($method, $url, $data, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function createMedication(array $schedule = []): array
    {
        return $this->mutation('POST', $this->root.'/medications', $this->payload($schedule))->assertCreated()->json('data.medication');
    }
}
