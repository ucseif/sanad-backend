<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UserTable;
use Tests\TestCase;

class AuthPreparationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UserTable::create();
    }

    public function test_email_availability_is_normalized_private_and_never_reserves(): void
    {
        $this->user();
        $this->postJson('/api/auth/availability/email', ['email' => "\u{00A0}AMINA@EXAMPLE.COM "])
            ->assertOk()->assertExactJson(['code' => 'availability-checked', 'data' => ['available' => false]]);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/availability/email', ['email' => 'new@example.com'])
                ->assertOk()->assertExactJson(['code' => 'availability-checked', 'data' => ['available' => true]]);
        }
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_username_availability_preserves_case_insensitive_identity_without_personal_data(): void
    {
        $this->user();
        $this->postJson('/api/auth/availability/username', ['username' => ' AMINA_1 '])
            ->assertOk()->assertExactJson(['code' => 'availability-checked', 'data' => ['available' => false]]);
        $this->postJson('/api/auth/availability/username', ['username' => 'New_1'])
            ->assertOk()->assertExactJson(['code' => 'availability-checked', 'data' => ['available' => true]]);
        $this->assertSame(1, DB::table('users')->count());
    }

    #[DataProvider('invalidAvailabilityInputs')]
    public function test_availability_rejects_invalid_inputs_with_stable_field_codes(string $field, mixed $value, string $code): void
    {
        $this->postJson('/api/auth/availability/'.$field, [$field => $value])->assertStatus(422)
            ->assertJsonPath('code', 'validation-failed')->assertJsonPath('error_codes.'.$field.'.0', $code);
        $this->assertSame(0, DB::table('users')->count());
    }

    public static function invalidAvailabilityInputs(): array
    {
        return [
            ['email', 'invalid', 'invalid-email'], ['email', null, 'required'], ['email', [], 'required'],
            ['email', ['invalid'], 'invalid-email'],
            ['email', str_repeat('a', 255).'@example.com', 'too-long'],
            ['username', 'abc', 'too-short'], ['username', str_repeat('a', 16), 'too-long'],
            ['username', 'مستخدم', 'invalid-username'], ['username', null, 'required'],
        ];
    }

    public function test_unknown_email_and_wrong_password_have_identical_generic_failures(): void
    {
        $this->user();
        $unknown = $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'WrongPass1!']);
        $wrong = $this->postJson('/api/auth/login', ['email' => 'amina@example.com', 'password' => 'WrongPass1!']);
        $unknown->assertStatus(401)->assertJsonPath('code', 'invalid-credentials');
        $wrong->assertStatus(401)->assertExactJson($unknown->json());
        $this->assertNoAuthentication($wrong);
    }

    public function test_valid_credentials_prepare_only_without_updating_user_or_granting_access(): void
    {
        $user = $this->user();
        // Exercise existing email casing/spacing through SQL, independent of model normalization.
        DB::table('users')->where('id', $user->id)->update(['email' => ' Amina@Example.com ']);
        $before = (array) DB::table('users')->first();
        $response = $this->postJson('/api/auth/login', ['email' => ' AMINA@EXAMPLE.COM ', 'password' => 'ValidPass1!',
            'otp_verified' => true, 'verified' => true, 'otp_verification_token' => 'forged-proof']);
        $response->assertOk()->assertExactJson($this->preparation('login-prepared'));
        $this->assertSame($before, (array) DB::table('users')->first());
        $this->assertNoAuthentication($response);
        $this->getJson('/api/user')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_login_rejects_malformed_input_without_starting_authentication(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'bad'])->assertStatus(422)
            ->assertJsonPath('error_codes.email.0', 'invalid-email')->assertJsonPath('error_codes.password.0', 'required');
    }

    public function test_unusable_stored_password_is_a_generic_credential_failure(): void
    {
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update(['password' => 'unusable-legacy-hash']);
        $this->postJson('/api/auth/login', ['email' => 'amina@example.com', 'password' => 'ValidPass1!'])
            ->assertStatus(401)->assertExactJson(['code' => 'invalid-credentials', 'message' => 'The email or password is incorrect.']);
        $this->assertSame('unusable-legacy-hash', DB::table('users')->value('password'));
    }

    public function test_login_has_an_ip_backstop_when_an_attacker_rotates_email_addresses(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'unknown'.$i.'@example.com', 'password' => 'WrongPass1!'])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['email' => 'another@example.com', 'password' => 'WrongPass1!'])
            ->assertStatus(429)->assertJsonPath('code', 'too-many-requests');
    }

    #[DataProvider('roles')]
    public function test_registration_preparation_preserves_rules_without_creating_any_account(string $role): void
    {
        $input = [...$this->registration(), 'role' => $role, 'otp_verified' => true, 'otp_verification_token' => 'forged'];
        $response = $this->postJson('/api/auth/register/prepare', $input);
        $response->assertOk()->assertExactJson($this->preparation('registration-prepared'));
        $this->assertSame(0, DB::table('users')->count());
        $this->assertNoAuthentication($response);
        $this->getJson('/api/user')->assertStatus(401);
    }

    public static function roles(): array
    {
        return [['patient'], ['caregiver']];
    }

    public function test_registration_exposes_localizable_validation_codes_and_creates_nothing(): void
    {
        $this->postJson('/api/auth/register/prepare', [...$this->registration(), 'role' => 'admin',
            'password' => 'weak', 'password_confirmation' => 'different', 'phone_number' => '123'])
            ->assertStatus(422)->assertJsonPath('code', 'validation-failed')
            ->assertJsonPath('error_codes.role.0', 'invalid-role')
            ->assertJsonPath('error_codes.password.0', 'weak-password')
            ->assertJsonPath('error_codes.password_confirmation.0', 'password-mismatch')
            ->assertJsonPath('error_codes.phone_number.0', 'invalid-phone-number');
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_registration_maps_duplicate_identities_to_existing_flutter_failure_codes(): void
    {
        $this->user();
        $this->postJson('/api/auth/register/prepare', $this->registration())->assertStatus(422)
            ->assertJsonPath('error_codes.email.0', 'email-already-in-use')
            ->assertJsonPath('error_codes.username.0', 'username-taken');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_email_and_username_availability_share_an_ip_rate_limit_and_recover(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/auth/availability/email', ['email' => 'new@example.com'])->assertOk();
        }
        $limited = $this->postJson('/api/auth/availability/username', ['username' => 'New_1']);
        $limited->assertStatus(429)->assertJsonPath('code', 'too-many-requests')->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/availability/username', ['username' => 'New_1'])->assertOk();
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_login_rate_limiting_uses_normalized_identity_and_never_authenticates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'WrongPass1!'])->assertStatus(401);
        }
        $response = $this->postJson('/api/auth/login', ['email' => ' UNKNOWN@EXAMPLE.COM ', 'password' => 'WrongPass1!']);
        $response->assertStatus(429)->assertJsonPath('code', 'too-many-requests')->assertHeader('Retry-After');
        $this->assertNoAuthentication($response);
    }

    public function test_registration_preparation_is_rate_limited_without_persistence(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register/prepare', $this->registration())->assertOk();
        }
        $this->postJson('/api/auth/register/prepare', $this->registration())->assertStatus(429)->assertJsonPath('code', 'too-many-requests');
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_protected_profile_rejects_anonymous_and_forged_bearer_requests(): void
    {
        $this->getJson('/api/user')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->withToken('client-invented-token')->getJson('/api/user')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_missing_server_proofs_and_authentication_cannot_be_bypassed(): void
    {
        foreach (['/api/auth/register', '/api/auth/otp/verify', '/api/auth/logout'] as $path) {
            $this->postJson($path, ['otp_verified' => true])->assertStatus($path === '/api/auth/logout' ? 401 : 422);
        }
    }

    public function test_profile_serializer_has_an_explicit_safe_contract_without_bypassing_otp(): void
    {
        // Test serialization directly: no actingAs, guard login or minted token.
        $user = $this->user();
        $user->remember_token = 'private-remember-token';
        $user->setRelation('tokens', collect([['token' => 'private-token-hash']]));
        $profile = (new UserResource($user))->resolve(Request::create('/api/user'));
        $this->assertSame((string) $user->id, $profile['id']);
        $this->assertSame('patient', $profile['role']);
        $this->assertSame('أمينة Hassan', $profile['name']);
        $this->assertSame('+201012345678', $profile['phone_number']);
        $this->assertSame('القاهرة', $profile['region']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T.*Z\z/', $profile['created_at']);
        foreach (['password', 'remember_token', 'tokens', 'email_verified_at'] as $field) {
            $this->assertArrayNotHasKey($field, $profile);
        }

        $legacy = new User(['name' => 'Existing name', 'email' => 'legacy@example.com', 'password' => 'LegacyPass1!']);
        $legacy->save();
        $legacyProfile = (new UserResource($legacy))->resolve(Request::create('/api/user'));
        $this->assertNull($legacyProfile['role']);
        $this->assertNull($legacyProfile['username']);
        $this->assertNull($legacyProfile['phone_number']);
        $this->assertGuest();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    private function assertNoAuthentication(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertGuest();
        $this->assertFalse(app('session')->isStarted());
    }

    private function preparation(string $code): array
    {
        return ['code' => $code, 'data' => ['status' => 'prepared', 'otp_required' => true,
            'otp_started' => false, 'authenticated' => false, 'next_step' => 'server-verified-otp']];
    }

    private function registration(): array
    {
        return ['username' => 'Amina_1', 'first_name' => 'أمينة', 'last_name' => 'Hassan', 'email' => 'amina@example.com',
            'password' => 'ValidPass1!', 'password_confirmation' => 'ValidPass1!', 'role' => 'patient',
            'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'القاهرة', 'address' => '12 Nile Street'];
    }

    private function user(): User
    {
        $user = new User($this->registration());
        $user->role = UserRole::Patient;
        $user->save();
        $this->assertTrue(Hash::check('ValidPass1!', $user->password));

        return $user;
    }
}
