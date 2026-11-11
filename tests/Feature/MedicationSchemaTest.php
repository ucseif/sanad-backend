<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MedicationTables;
use Tests\TestCase;

class MedicationSchemaTest extends TestCase
{
    public function test_additive_schema_and_immutable_event_guards_exist(): void
    {
        $path = database_path('migrations/phase6/2026_11_11_000000_create_medication_management.php');
        $this->assertFileExists($path);
        MedicationTables::create();
        foreach (['medications', 'medication_schedule_revisions', 'medication_schedule_times', 'medication_schedule_weekdays',
            'medication_dose_occurrences', 'medication_intake_events', 'medication_preferences',
            'medication_notification_candidates', 'medication_audit_events', 'medication_mutation_requests', 'medication_patient_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertGreaterThanOrEqual(5, DB::table('pg_trigger')->where('tgisinternal', false)->where('tgname', 'like', 'medication_%')->count());
    }
}
