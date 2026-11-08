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
            throw new RuntimeException('SANAD profile integrity requires PostgreSQL. Use an isolated PostgreSQL database for migration tests.');
        }

        DB::transaction(function (): void {
            // Prevent identity changes between the preflight and index creation.
            DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');

            $conflicts = DB::table('users')->selectRaw('lower(trim(email))')
                ->groupByRaw('lower(trim(email))')->havingRaw('count(*) > 1')->exists();

            if ($conflicts) {
                throw new RuntimeException('SANAD migration stopped: conflicting email identities exist after case/space normalization. Review and resolve them explicitly before retrying; no user records were changed.');
            }

            Schema::table('users', function (Blueprint $table): void {
                // Stage 1: existing accounts remain intact and explicitly incomplete.
                // A later reviewed migration can enforce NOT NULL after real remediation.
                $table->string('username', 15)->nullable();
                $table->string('first_name', 80)->nullable();
                $table->string('last_name', 80)->nullable();
                $table->string('phone_number', 16)->nullable();
                $table->string('country', 80)->nullable();
                $table->string('region', 80)->nullable();
                $table->string('address', 250)->nullable();
                $table->string('role', 16)->nullable();
            });

            DB::statement('CREATE UNIQUE INDEX users_email_normalized_unique ON users (lower(trim(email)))');
            DB::statement('CREATE UNIQUE INDEX users_username_normalized_unique ON users (lower(trim(username)))');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('patient', 'caregiver'))");
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            DB::statement('DROP INDEX users_username_normalized_unique');
            DB::statement('DROP INDEX users_email_normalized_unique');

            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(['username', 'first_name', 'last_name', 'phone_number', 'country', 'region', 'address', 'role']);
            });
        });
    }
};
