<?php

namespace Tests\Support;

/** Real schema in the guarded per-test PostgreSQL namespace. */
final class UserTable
{
    public static function create(): void
    {
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php'))->up();
        (require database_path('migrations/2026_11_06_015201_create_personal_access_tokens_table.php'))->up();
        (require database_path('migrations/2026_11_07_000001_create_auth_attempts_table.php'))->up();
        (require database_path('migrations/2026_11_07_000002_add_password_recovery_support.php'))->up();
        (require database_path('migrations/0001_01_01_000002_create_jobs_table.php'))->up();
    }
}
