<?php

namespace Tests\Feature;

use App\Application\Auth\OtpAuthentication;
use App\Enums\UserRole;
use App\Models\AuthAttempt;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\UserTable;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    protected string $submittedCode;

    protected function setUp(): void
    {
        parent::setUp();
        UserTable::create();
        $this->submittedCode = (string) random_int(100000, 999999);
        config(['services.wexora.api_key' => 'test-only-key', 'services.wexora.session_id' => 'test-only-session']);
        $this->fakeProvider();
    }

    public function test_signup_requires_provider_verification_and_consumes_a_single_use_proof(): void
    {
        $attempt = $this->startSignup();
        $this->assertSame(0, DB::table('users')->count());
        $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => bin2hex(random_bytes(32)), 'verified' => true])->assertStatus(409);
        $this->send($attempt)->assertStatus(202);
        $proof = $this->verify($attempt, 'signup')->assertOk()->json('data.verification_proof');
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->verify($attempt, 'signup')->assertStatus(409);
        $result = $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => $proof]);
        $result->assertCreated()->assertJsonPath('data.user.role', 'caregiver')->assertJsonPath('code', 'registered');
        $this->assertIsString($result->json('data.token'));
        $this->assertSame(1, DB::table('users')->count());
        $this->assertNotNull(User::first()->phone_verified_at);
        $this->assertSame(app(OtpAuthentication::class)->phoneHash(User::first()->phone_number), User::first()->phone_verified_hash);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => $proof])->assertStatus(409);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertFalse($result->headers->has('Set-Cookie'));
        $this->assertGuest();
    }

    public function test_login_is_bound_to_stored_phone_and_only_otp_can_issue_token(): void
    {
        $user = $this->user();
        $attempt = $this->startLogin();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->send([...$attempt, 'phone_number' => '+15551234567'])->assertStatus(202);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/otp') && $request['to'] === $user->phone_number);
        $this->verify($attempt, 'signup')->assertStatus(404);
        $result = $this->verify($attempt, 'login')->assertOk()->assertJsonPath('code', 'authenticated');
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertSame(app(OtpAuthentication::class)->phoneHash($user->phone_number), $user->fresh()->phone_verified_hash);
        $token = $result->json('data.token');
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->verify($attempt, 'login')->assertStatus(409);
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('data.user.id', (string) $user->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_login_returns_only_calling_code_and_last_four_phone_digits_after_valid_credentials(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = $this->user();
        foreach (['+20', '+966', '+971', '+965', '+974', '+968', '+973', '+962', '+1', '+44'] as $callingCode) {
            $user->forceFill(['phone_number' => $callingCode.'1234567890'])->save();
            $response = $this->postJson('/api/auth/login/start', ['email' => $user->email, 'password' => 'ValidPass1!']);
            $response->assertCreated()->assertJsonPath('data.masked_phone_number', $callingCode.' •••••• 7890');
            $this->assertStringNotContainsString($user->phone_number, $response->getContent());
            $this->assertArrayNotHasKey('token', $response->json('data'));
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->postJson('/api/auth/login/start', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonMissingPath('data.masked_phone_number');
        Http::assertNothingSent();
    }

    public function test_invalid_codes_exhaust_the_budget_and_cannot_mint_tokens(): void
    {
        $this->fakeProvider(false);
        $this->user();
        $attempt = $this->startLogin();
        $this->send($attempt)->assertStatus(202);
        for ($i = 0; $i < 5; $i++) {
            $this->verify($attempt, 'login')->assertStatus(422)->assertJsonPath('code', 'invalid-code');
        }
        $this->verify($attempt, 'login')->assertStatus(429);
        $this->assertSame(5, DB::table('auth_attempts')->value('verify_count'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_expired_challenge_and_expired_proof_fail_closed(): void
    {
        $attempt = $this->startSignup();
        $this->send($attempt)->assertStatus(202);
        $this->travel(6)->minutes();
        $this->verify($attempt, 'signup')->assertStatus(410);
        $attempt = $this->startSignup('other@example.com', 'Other_1', '+15551234567');
        $this->send($attempt)->assertStatus(202);
        $proof = $this->verify($attempt, 'signup')->assertOk()->json('data.verification_proof');
        $this->travel(6)->minutes();
        $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => $proof])->assertStatus(410);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_cross_account_secret_and_changed_account_binding_are_rejected(): void
    {
        $user = $this->user();
        $first = $this->startLogin();
        $second = $this->startLogin();
        $this->send($first)->assertStatus(202);
        $this->verify([...$first, 'attempt_token' => $second['attempt_token']], 'login')->assertStatus(404);
        DB::table('users')->where('id', $user->id)->update(['phone_number' => '+15551234567']);
        $this->verify($first, 'login')->assertStatus(409);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_resend_cooldown_cross_attempt_phone_budget_and_challenge_rotation(): void
    {
        $attempt = $this->startSignup();
        $this->send($attempt)->assertStatus(202);
        $this->send($attempt)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->send($attempt)->assertStatus(202);
        $this->travel(61)->seconds();
        $other = $this->startSignup('other@example.com', 'Other_1');
        $this->send($other)->assertStatus(202);
        $this->travel(61)->seconds();
        $this->send($attempt)->assertStatus(429);
        $this->assertSame(3, (int) DB::table('auth_attempts')->sum('send_count'));
    }

    public function test_provider_timeout_is_sanitized_and_failed_resend_invalidates_old_challenge(): void
    {
        $attempt = $this->startSignup();
        $this->send($attempt)->assertStatus(202);
        $this->travel(61)->seconds();
        $this->resetHttp();
        Http::fake(fn () => throw new ConnectionException('Simulated connection failure'));
        $this->send($attempt)->assertStatus(503)->assertJsonPath('code', 'otp-service-unavailable');
        $this->assertNull(DB::table('auth_attempts')->value('provider_id'));
        $this->verify($attempt, 'signup')->assertStatus(409);
        $this->assertSame(2, DB::table('auth_attempts')->value('send_count'));
    }

    public function test_unexpected_provider_response_never_verifies_or_issues_a_proof(): void
    {
        $attempt = $this->startSignup();
        $this->resetHttp();
        Http::fake(['*/otp' => Http::response(['unexpected' => true], 202)]);
        $this->send($attempt)->assertStatus(503);
        $this->verify($attempt, 'signup')->assertStatus(409);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_duplicate_registration_never_consumes_proof_or_creates_a_second_user(): void
    {
        $attempt = $this->startSignup();
        $this->send($attempt)->assertStatus(202);
        $proof = $this->verify($attempt, 'signup')->assertOk()->json('data.verification_proof');
        $this->user();
        $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => $proof])->assertStatus(422);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame('verified', DB::table('auth_attempts')->value('status'));
    }

    public function test_pending_state_contains_no_plaintext_password_code_or_bearer_secret(): void
    {
        $attempt = $this->startSignup();
        $row = (array) DB::table('auth_attempts')->first();
        $this->assertFalse(str_contains(json_encode($row), $this->registration()['password']));
        $this->assertFalse(str_contains(json_encode($row), $attempt['attempt_token']));
        $this->assertSame(hash('sha256', $attempt['attempt_token']), $row['secret_hash']);
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_phone_cooldown_cannot_be_bypassed_with_another_attempt(): void
    {
        $first = $this->startSignup();
        $second = $this->startSignup('other@example.com', 'Other_1');
        $this->send($first)->assertStatus(202);
        $this->send($second)->assertStatus(429);
        $this->assertSame(1, (int) DB::table('auth_attempts')->sum('send_count'));
    }

    public function test_session_timezone_and_persisted_expiry_are_utc_consistent(): void
    {
        $this->startSignup();
        $this->assertSame('UTC', DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone);
        $expiry = AuthAttempt::first()->expires_at;
        $this->assertGreaterThanOrEqual(599, $expiry->timestamp - now()->timestamp);
        $this->assertLessThanOrEqual(600, $expiry->timestamp - now()->timestamp);
    }

    public function test_long_password_fails_explicitly_instead_of_bcrypt_truncation(): void
    {
        $password = 'ValidPass1!'.str_repeat('x', 80);
        $this->postJson('/api/auth/signup/start', [...$this->registration(), 'password' => $password,
            'password_confirmation' => $password])->assertStatus(422)->assertJsonPath('code', 'weak-password');
        $this->assertSame(0, DB::table('auth_attempts')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_logout_revokes_only_the_current_verified_token_and_expiry_is_enforced(): void
    {
        $this->user();
        $first = $this->startLogin();
        $this->send($first)->assertStatus(202);
        $tokenOne = $this->verify($first, 'login')->assertOk()->json('data.token');
        $this->travel(61)->seconds();
        $second = $this->startLogin();
        $this->send($second)->assertStatus(202);
        $tokenTwo = $this->verify($second, 'login')->assertOk()->json('data.token');
        $this->withToken($tokenOne)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenTwo)->getJson('/api/user')->assertOk();
        $this->travel(8)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenTwo)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_cleanup_removes_only_retained_expired_attempts_and_not_users(): void
    {
        $this->startSignup();
        $this->travel(26)->hours();
        $this->startSignup('other@example.com', 'Other_1');
        $this->artisan('auth:prune-attempts')->assertExitCode(0);
        $this->assertSame(1, DB::table('auth_attempts')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_phone_guess_window_uses_last_verification_not_attempt_creation(): void
    {
        $this->fakeProvider(false);
        $this->user();
        $first = $this->startLogin();
        $this->send($first)->assertStatus(202);
        $this->travel(4)->minutes();
        for ($i = 0; $i < 5; $i++) {
            $this->verify($first, 'login')->assertStatus(422);
        }
        $second = $this->startLogin();
        $this->send($second)->assertStatus(202);
        for ($i = 0; $i < 5; $i++) {
            $this->verify($second, 'login')->assertStatus(422);
        }
        // A new attempt later must still count guesses made within the preceding 15m,
        // even when the old attempts themselves have expired.
        $this->travel(12)->minutes();
        $third = $this->startLogin();
        $this->send($third)->assertStatus(202);
        $this->verify($third, 'login')->assertStatus(429);
        $this->assertSame(10, (int) DB::table('auth_attempts')->sum('verify_count'));
    }

    public function test_token_storage_failure_rolls_back_signup_and_preserves_unconsumed_proof(): void
    {
        $attempt = $this->startSignup();
        $this->send($attempt)->assertStatus(202);
        $proof = $this->verify($attempt, 'signup')->assertOk()->json('data.verification_proof');
        DB::statement('ALTER TABLE personal_access_tokens ADD CONSTRAINT reject_test_tokens CHECK (false)');
        $this->postJson('/api/auth/register', [...$attempt, 'verification_proof' => $proof])->assertStatus(503);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame('verified', DB::table('auth_attempts')->value('status'));
    }

    public function test_real_cross_account_attempt_pairing_never_authenticates_another_user(): void
    {
        $firstUser = $this->user();
        $otherUser = new User([...$this->registration(), 'email' => 'other@example.com', 'username' => 'Other_1', 'phone_number' => '+15551234567']);
        $otherUser->role = UserRole::Patient;
        $otherUser->save();
        $first = $this->startLogin();
        $otherStart = $this->postJson('/api/auth/login/start', ['email' => 'other@example.com', 'password' => 'ValidPass1!'])->assertCreated();
        $this->send($first)->assertStatus(202);
        $this->verify([...$first, 'attempt_token' => $otherStart->json('data.attempt_token')], 'login')->assertStatus(404);
        $verified = $this->postJson('/api/auth/otp/verify', [...$first, 'purpose' => 'login', 'code' => $this->submittedCode,
            'user_id' => $otherUser->id, 'email' => 'other@example.com'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($verified->json('data.token'))->getJson('/api/user?user_id='.$otherUser->id)->assertOk()
            ->assertJsonPath('data.user.id', (string) $firstUser->id);
    }

    public function test_signup_proof_cannot_be_moved_to_another_signup_attempt(): void
    {
        $first = $this->startSignup();
        $this->send($first)->assertStatus(202);
        $firstProof = $this->verify($first, 'signup')->assertOk()->json('data.verification_proof');
        $this->travel(61)->seconds();
        $second = $this->startSignup('other@example.com', 'Other_1');
        $this->send($second)->assertStatus(202);
        $this->verify($second, 'signup')->assertOk();
        $this->postJson('/api/auth/register', [...$second, 'verification_proof' => $firstProof])->assertStatus(404);
        $this->assertSame(0, DB::table('users')->count());
        $this->postJson('/api/auth/register', [...$first, 'verification_proof' => $firstProof, 'role' => 'patient'])->assertCreated()
            ->assertJsonPath('data.user.role', 'caregiver');
    }

    public function test_otp_endpoint_ip_limits_reject_repeated_invalid_attempts_without_provider_calls(): void
    {
        $invalid = ['attempt_id' => (string) Str::uuid(), 'attempt_token' => bin2hex(random_bytes(32))];
        for ($i = 0; $i < 10; $i++) {
            $this->send($invalid)->assertStatus(404);
        }
        $this->send($invalid)->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 20; $i++) {
            $this->verify($invalid, 'login')->assertStatus(404);
        }
        $this->verify($invalid, 'login')->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    protected function registration(): array
    {
        return ['username' => 'Amina_1', 'first_name' => 'Amina', 'last_name' => 'Hassan', 'email' => 'amina@example.com',
            'password' => 'ValidPass1!', 'password_confirmation' => 'ValidPass1!', 'role' => 'caregiver',
            'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'Cairo', 'address' => '12 Nile Street'];
    }

    protected function startSignup(string $email = 'amina@example.com', string $username = 'Amina_1', string $phone = '+201012345678'): array
    {
        $result = $this->postJson('/api/auth/signup/start', [...$this->registration(), 'email' => $email, 'username' => $username, 'phone_number' => $phone])->assertCreated();

        return ['attempt_id' => $result->json('data.attempt_id'), 'attempt_token' => $result->json('data.attempt_token')];
    }

    protected function startLogin(): array
    {
        $result = $this->postJson('/api/auth/login/start', ['email' => 'amina@example.com', 'password' => 'ValidPass1!'])->assertCreated();

        return ['attempt_id' => $result->json('data.attempt_id'), 'attempt_token' => $result->json('data.attempt_token')];
    }

    protected function send(array $attempt)
    {
        return $this->postJson('/api/auth/otp/send', $attempt);
    }

    protected function verify(array $attempt, string $purpose)
    {
        return $this->postJson('/api/auth/otp/verify', [...$attempt, 'purpose' => $purpose, 'code' => $this->submittedCode]);
    }

    protected function user(): User
    {
        $user = new User($this->registration());
        $user->role = UserRole::Caregiver;
        $user->save();

        return $user;
    }

    protected function fakeProvider(bool $verified = true): void
    {
        $this->resetHttp();
        Http::fake(function ($request) use ($verified) {
            if (str_ends_with($request->url(), '/verify')) {
                return Http::response(['verified' => $verified], $verified ? 200 : 422);
            }

            return Http::response(['id' => bin2hex(random_bytes(16)), 'expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s')], 202);
        });
    }

    protected function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }
}
