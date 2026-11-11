<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Block concurrent relationship writes throughout preflight and constraint replacement.
            DB::statement('LOCK TABLE caregiver_links IN SHARE ROW EXCLUSIVE MODE');
            if (DB::table('caregiver_links')->select('patient_id')->whereIn('status', ['pending', 'active'])
                ->groupBy('patient_id')->havingRaw('count(*) > 2')->exists()) {
                throw new RuntimeException('Caregiver limit migration stopped: patients have more than two live links. Resolve excess relationships explicitly before retrying; no relationships were changed.');
            }
            // A surviving slot 3 can always move to a free slot after the preflight.
            // Preserve IDs, lifecycle state, timestamps and terminal history.
            DB::statement(<<<'SQL'
                UPDATE caregiver_links AS moving
                SET slot_number = CASE WHEN EXISTS (
                    SELECT 1 FROM caregiver_links AS occupied
                    WHERE occupied.patient_id = moving.patient_id
                        AND occupied.status IN ('pending','active') AND occupied.slot_number = 1
                ) THEN 2 ELSE 1 END
                WHERE moving.status IN ('pending','active') AND moving.slot_number = 3
                SQL);
            DB::statement('ALTER TABLE caregiver_links DROP CONSTRAINT caregiver_links_slot_check');
            DB::statement("ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_slot_check CHECK ((status IN ('pending','active') AND slot_number IS NOT NULL AND slot_number BETWEEN 1 AND 2) OR (status IN ('declined','revoked','cancelled') AND slot_number IS NULL))");
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE caregiver_links IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('ALTER TABLE caregiver_links DROP CONSTRAINT caregiver_links_slot_check');
            DB::statement("ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_slot_check CHECK ((status IN ('pending','active') AND slot_number IS NOT NULL AND slot_number BETWEEN 1 AND 3) OR (status IN ('declined','revoked','cancelled') AND slot_number IS NULL))");
        });
    }
};
