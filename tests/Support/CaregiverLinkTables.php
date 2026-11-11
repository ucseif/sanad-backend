<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\User;

final class CaregiverLinkTables
{
    public static function create(): void
    {
        UserTable::create();
        $migration = database_path('migrations/2026_11_08_000000_create_caregiver_links_table.php');
        if (file_exists($migration)) {
            (require $migration)->up();
        }
        (require database_path('migrations/2026_11_09_000000_limit_caregiver_links_to_two_slots.php'))->up();
    }

    public static function user(string $username, UserRole $role): User
    {
        $user = new User(['name' => $username.' Display', 'username' => $username,
            'email' => $username.'@example.com', 'password' => 'ValidPass1!',
            'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'Cairo', 'address' => 'Private address']);
        $user->role = $role;
        $user->save();

        return $user;
    }
}
