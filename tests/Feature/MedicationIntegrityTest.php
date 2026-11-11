<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicationTestCase;

class MedicationIntegrityTest extends MedicationTestCase
{
    private function rejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('The database accepted an invalid medication mutation.');
        } catch (QueryException $error) {
            $this->assertContains($error->getCode(), ['23514', '23503', '23505']);
        }
    }

    public function test_patient_ownership_and_revision_and_audit_are_immutable(): void
    {
        $med = $this->createMedication();
        $this->rejected(fn () => DB::table('medications')->where('id', $med['id'])->update(['patient_id' => $this->other->id]));
        $this->rejected(fn () => DB::table('medication_schedule_revisions')->update(['prescription' => json_encode($this->payload()['prescription'] + ['extra' => true])]));
        $this->rejected(fn () => DB::table('medication_audit_events')->delete());
        $this->rejected(fn () => DB::table('medication_schedule_times')->update(['local_time' => '10:00']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(1, DB::table('medications')->count());
    }

    public function test_cross_patient_dose_fk_and_null_state_constraints(): void
    {
        $this->createMedication();
        $this->getJson($this->root.'/medication-doses?from=2026-11-11&to=2026-11-11&timezone=UTC')->assertOk();
        $dose = (array) DB::table('medication_dose_occurrences')->first();
        $this->rejected(fn () => DB::table('medication_dose_occurrences')->where('id', $dose['id'])->update(['patient_id' => $this->other->id]));
        $this->rejected(fn () => DB::table('medication_dose_occurrences')->where('id', $dose['id'])->update(['actual_intake_at' => '2026-11-11T06:00:00Z']));
        $this->rejected(fn () => DB::table('medication_intake_events')->insert(['id' => (string) Str::uuid(), 'occurrence_id' => $dose['id'],
            'medication_id' => $dose['medication_id'], 'patient_id' => $this->patient->id, 'actor_id' => $this->carer->id,
            'event_type' => 'taken', 'effective_at' => now(), 'recorded_at' => now()]));
    }

    public function test_specific_day_recurrence_cannot_be_mutated_by_adding_a_time(): void
    {
        $med = $this->createMedication(['frequency' => 'specific_days', 'weekdays' => [1, 3], 'times' => ['08:00']]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->rejected(fn () => DB::table('medication_schedule_times')->insert(['revision_id' => $med['current_revision']['id'], 'local_time' => '12:00']));
    }

    public function test_malformed_nested_types_and_missing_idempotency_are_validation_errors(): void
    {
        foreach (['times' => '08:00', 'weekdays' => 'Monday'] as $key => $value) {
            $this->mutation('POST', $this->root.'/medications', $this->payload([$key => $value]))->assertUnprocessable();
        }
        foreach ([['1', '3'], [1, '1']] as $weekdays) {
            $this->mutation('POST', $this->root.'/medications', $this->payload(['frequency' => 'specific_days', 'weekdays' => $weekdays]))->assertUnprocessable();
        }
        $this->postJson($this->root.'/medications', $this->payload())->assertUnprocessable();
    }
}
