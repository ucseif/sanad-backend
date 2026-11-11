<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Caregiver relationship integrity requires PostgreSQL.');
        }
        Schema::create('caregiver_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('patient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('caregiver_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16);
            $table->smallInteger('slot_number')->nullable();
            $table->timestampTz('invited_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['patient_id', 'status', 'created_at', 'id']);
            $table->index(['caregiver_id', 'status', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_status_check CHECK (status IN ('pending','active','declined','revoked','cancelled'))");
        DB::statement('ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_participants_check CHECK (patient_id <> caregiver_id)');
        DB::statement("ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_slot_check CHECK ((status IN ('pending','active') AND slot_number IS NOT NULL AND slot_number BETWEEN 1 AND 3) OR (status IN ('declined','revoked','cancelled') AND slot_number IS NULL))");
        DB::statement(<<<'SQL'
            ALTER TABLE caregiver_links ADD CONSTRAINT caregiver_links_lifecycle_check CHECK (
                (status = 'pending' AND accepted_at IS NULL AND ended_at IS NULL AND ended_by IS NULL) OR
                (status = 'active' AND accepted_at IS NOT NULL AND accepted_at >= invited_at AND ended_at IS NULL AND ended_by IS NULL) OR
                (status IN ('declined','cancelled') AND accepted_at IS NULL AND ended_at IS NOT NULL AND ended_at >= invited_at AND ended_by IS NOT NULL
                    AND ((status = 'declined' AND ended_by = caregiver_id) OR (status = 'cancelled' AND ended_by = patient_id))) OR
                (status = 'revoked' AND accepted_at IS NOT NULL AND accepted_at >= invited_at AND ended_at IS NOT NULL AND ended_at >= accepted_at
                    AND ended_by IS NOT NULL AND ended_by IN (patient_id, caregiver_id))
            )
            SQL);
        DB::statement("CREATE UNIQUE INDEX caregiver_links_live_pair_unique ON caregiver_links (patient_id, caregiver_id) WHERE status IN ('pending','active')");
        DB::statement("CREATE UNIQUE INDEX caregiver_links_live_slot_unique ON caregiver_links (patient_id, slot_number) WHERE status IN ('pending','active')");
    }

    public function down(): void
    {
        Schema::dropIfExists('caregiver_links');
    }
};
