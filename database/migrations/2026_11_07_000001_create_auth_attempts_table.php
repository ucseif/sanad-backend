<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('secret_hash', 64)->unique();
            $table->string('purpose', 8);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('phone_hash', 64)->index();
            $table->string('binding_hash', 64)->nullable();
            $table->text('payload')->nullable();
            $table->text('provider_id')->nullable();
            $table->string('provider_hash', 64)->nullable()->unique();
            $table->string('status', 12)->default('pending');
            $table->smallInteger('send_count')->default(0);
            $table->smallInteger('verify_count')->default(0);
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampTz('last_verify_at')->nullable();
            $table->timestampTz('challenge_expires_at')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->string('proof_hash', 64)->nullable()->unique();
            $table->timestampTz('proof_expires_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_purpose_check CHECK (purpose IN ('signup', 'login'))");
        DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_status_check CHECK (status IN ('pending', 'verified', 'completed', 'locked'))");
        DB::statement('ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_counters_check CHECK (send_count >= 0 AND verify_count >= 0)');
        DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_user_check CHECK ((purpose = 'login' AND user_id IS NOT NULL) OR (purpose = 'signup' AND user_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_attempts');
    }
};
