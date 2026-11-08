<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE auth_attempts ALTER COLUMN purpose TYPE varchar(16)');
            DB::statement('ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_purpose_check');
            DB::statement('ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_user_check');
            DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_purpose_check CHECK (purpose IN ('signup', 'login', 'password_reset'))");
            DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_user_check CHECK ((purpose = 'signup' AND user_id IS NULL) OR (purpose = 'login' AND user_id IS NOT NULL) OR purpose = 'password_reset')");
            Schema::table('auth_attempts', function (Blueprint $table): void {
                $table->smallInteger('recovery_delivery_generation')->default(0);
            });
            DB::statement('ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_recovery_generation_check CHECK (recovery_delivery_generation >= 0 AND recovery_delivery_generation <= send_count)');
            Schema::table('users', function (Blueprint $table): void {
                $table->timestampTz('phone_verified_at')->nullable();
                $table->string('phone_verified_hash', 64)->nullable();
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('auth_attempts')->where('purpose', 'password_reset')->exists()) {
                throw new RuntimeException('Recovery attempts exist. Review retained state before reversing this migration; no records were deleted.');
            }
            DB::statement('ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_recovery_generation_check');
            DB::statement('ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_purpose_check');
            DB::statement('ALTER TABLE auth_attempts DROP CONSTRAINT auth_attempts_user_check');
            DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_purpose_check CHECK (purpose IN ('signup', 'login'))");
            DB::statement("ALTER TABLE auth_attempts ADD CONSTRAINT auth_attempts_user_check CHECK ((purpose = 'signup' AND user_id IS NULL) OR (purpose = 'login' AND user_id IS NOT NULL))");
            DB::statement('ALTER TABLE auth_attempts ALTER COLUMN purpose TYPE varchar(8)');
            Schema::table('auth_attempts', fn (Blueprint $table) => $table->dropColumn('recovery_delivery_generation'));
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone_verified_at', 'phone_verified_hash']));
        });
    }
};
