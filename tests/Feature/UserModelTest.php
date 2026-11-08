<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\UserTable;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    public function test_sanad_profile_attributes_are_assignable_but_role_and_security_metadata_are_guarded(): void
    {
        $user = new User(['username' => ' Amina_1 ', 'email' => ' AMINA@EXAMPLE.COM ',
            'first_name' => 'Amina', 'last_name' => 'Hassan', 'phone_number' => '+201012345678',
            'country' => 'Egypt', 'region' => 'Cairo', 'address' => '12 Nile Street',
            'id' => 999, 'role' => 'caregiver', 'email_verified_at' => now(), 'created_at' => now(),
            'remember_token' => 'untrusted']);
        $this->assertSame('Amina_1', $user->username);
        $this->assertSame('amina@example.com', $user->email);
        $this->assertSame('Amina', $user->first_name);
        foreach (['id', 'role', 'email_verified_at', 'created_at', 'remember_token'] as $field) {
            $this->assertNull($user->getAttribute($field));
        }
    }

    public function test_password_is_hashed_and_credentials_and_token_relations_are_hidden(): void
    {
        $user = new User(['password' => 'Abcdef12!']);
        $user->remember_token = 'private';
        $user->setRelation('tokens', collect([['token' => 'private-token-hash']]));
        $this->assertTrue(Hash::check('Abcdef12!', $user->password));
        foreach (['password', 'remember_token', 'tokens'] as $field) {
            $this->assertArrayNotHasKey($field, $user->toArray());
        }
        $this->assertTrue(method_exists($user, 'createToken'));
    }

    public function test_model_normalizes_unicode_whitespace_in_identities_and_derived_names(): void
    {
        UserTable::create();
        $user = new User(['username' => "\u{00A0}Amina_1\u{2003}", 'email' => "\u{FEFF}AMINA@EXAMPLE.COM\u{00A0}",
            'first_name' => "\u{2003}Amina\u{00A0}", 'last_name' => "\u{00A0}Hassan\u{2003}", 'password' => 'Abcdef12!']);
        $user->save();
        $this->assertSame('Amina_1', $user->username);
        $this->assertSame('amina@example.com', $user->email);
        $this->assertSame('Amina Hassan', $user->fresh()->name);
    }

    public function test_model_derives_name_and_casts_role_without_changing_legacy_names(): void
    {
        UserTable::create();
        $user = new User(['first_name' => ' Amina ', 'last_name' => ' Hassan ', 'name' => 'Untrusted name',
            'email' => 'amina@example.com', 'password' => 'Abcdef12!']);
        $user->role = UserRole::Patient;
        $user->save();
        $this->assertSame('Amina Hassan', $user->fresh()->name);
        $this->assertSame(UserRole::Patient, $user->fresh()->role);
        $this->assertSame('patient', $user->toArray()['role']);
        $this->assertNotNull($user->id);
        $this->assertNotNull($user->created_at);

        $legacy = new User(['name' => 'Existing name', 'email' => 'legacy@example.com', 'password' => 'password']);
        $legacy->save();
        $this->assertSame('Existing name', $legacy->fresh()->name);
        $this->assertNull($legacy->fresh()->role);
    }
}
