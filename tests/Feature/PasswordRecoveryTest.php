<?php

namespace Tests\Feature;

use App\Application\Auth\PasswordRecovery;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UserTable;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        UserTable::create();
        $this->code = (string) random_int(100000, 999999);
        config(['services.wexora.api_key' => 'test-key', 'services.wexora.session_id' => 'test-session']);
        $this->provider();
    }

    public function test_email_eligibility_is_explicit_and_creates_no_attempt_message_or_session(): void
    {
        $this->user();
        $this->user('unverified@example.com', false);
        foreach ([' AMINA@EXAMPLE.COM ' => true, 'unknown@example.com' => false, 'unverified@example.com' => false] as $email => $eligible) {
            $this->postJson('/api/auth/password/eligibility', ['email' => $email])->assertOk()
                ->assertExactJson(['code' => 'recovery-eligibility-checked', 'data' => ['eligible' => $eligible]]);
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('auth_attempts')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_email_eligibility_and_phone_validation_share_recovery_throttles(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/password/eligibility', ['email' => 'unknown@example.com'])->assertOk();
        }
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.com', 'phone_number' => '+201012345678'])
                ->assertStatus(422)->assertJsonPath('code', 'recovery-not-eligible');
        }
        $this->postJson('/api/auth/password/eligibility', ['email' => ' UNKNOWN@EXAMPLE.COM '])->assertStatus(429);
        $this->assertSame(0, DB::table('auth_attempts')->count());
        Http::assertNothingSent();
    }

    public function test_send_is_queued_and_delivers_only_to_the_stored_verified_phone(): void
    {
        $user = $this->user();
        $attempt = $this->forgot();
        $this->postJson('/api/auth/password/otp/send', [...$attempt, 'phone_number' => '+15551234567'])->assertStatus(202);
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->work();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/otp') && $request['to'] === $user->phone_number);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_mismatched_phone_blocks_before_creating_an_attempt_or_dispatching(): void
    {
        $this->user();
        $this->postJson('/api/auth/password/forgot', ['email' => 'amina@example.com', 'phone_number' => '+15551234567'])
            ->assertStatus(422)->assertJsonPath('code', 'recovery-not-eligible')->assertJsonMissingPath('data');
        $this->assertSame(0, DB::table('auth_attempts')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        Http::assertNothingSent();
    }

    public function test_real_attempt_and_dispatch_are_explicitly_confirmed(): void
    {
        $this->user();
        $response = $this->postJson('/api/auth/password/forgot', ['email' => 'amina@example.com', 'phone_number' => '+201012345678'])
            ->assertStatus(202)->assertJsonPath('data.recovery_confirmed', true)->assertJsonPath('data.purpose', 'password_reset');
        $this->assertStringNotContainsString('+201012345678', $response->getContent());
        $this->assertSame(1, DB::table('auth_attempts')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $attempt = ['attempt_id' => $response->json('data.attempt_id'), 'attempt_token' => $response->json('data.attempt_token')];
        $this->send($attempt)->assertStatus(202)->assertJsonPath('data.recovery_confirmed', true);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->verify($attempt)->assertStatus(422);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/otp'));
    }

    public function test_recovery_requires_a_valid_international_phone_number(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach ([null, '', '201012345678', '+20 1012345678', '+123', ['+201012345678']] as $phone) {
            $this->postJson('/api/auth/password/forgot', ['email' => 'amina@example.com', 'phone_number' => $phone])
                ->assertStatus(422)->assertJsonValidationErrors('phone_number');
        }
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('auth_attempts')->count());
    }

    public function test_recovery_trims_the_submitted_phone_consistently_with_registration(): void
    {
        $user = $this->user();
        $attempt = $this->forgot(' AMINA@EXAMPLE.COM ', ' +201012345678 ');
        $this->send($attempt)->assertStatus(202);
        $this->work();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/otp') && $request['to'] === $user->phone_number);
    }

    public function test_unknown_unverified_and_changed_accounts_cannot_start_recovery(): void
    {
        $this->user('unverified@example.com', false);
        $changed = $this->user('changed@example.com');
        DB::table('users')->where('id', $changed->id)->update(['phone_number' => '+15551234567']);
        foreach (['unknown@example.com', 'unverified@example.com', 'changed@example.com'] as $email) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email, 'phone_number' => '+201012345678'])
                ->assertStatus(422)->assertJsonPath('code', 'recovery-not-eligible');
        }
        $this->assertSame(0, DB::table('auth_attempts')->count());
        Http::assertNothingSent();
    }

    public function test_success_consumes_proof_updates_password_revokes_every_token_and_never_logs_in(): void
    {
        $user = $this->user();
        $oldToken = $this->loginToken();
        $this->travel(61)->seconds();
        $otherToken = $this->loginToken();
        $this->travel(61)->seconds();
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        $this->assertSame(2, DB::table('personal_access_tokens')->count());
        $response = $this->reset($attempt, $proof);
        $response->assertOk()->assertExactJson(['code' => 'password-reset', 'data' => ['authenticated' => false, 'login_required' => true]]);
        $this->assertTrue(Hash::check('NewStrongPass1!', $user->fresh()->password));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        foreach ([$oldToken, $otherToken] as $token) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => 'amina@example.com', 'password' => 'ValidPass1!'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'amina@example.com', 'password' => 'NewStrongPass1!'])->assertOk();
        $this->reset($attempt, $proof)->assertStatus(409);
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_invalid_code_exhausts_attempt_budget_without_creating_a_session(): void
    {
        $this->user();
        $this->provider(false);
        foreach (['amina@example.com'] as $email) {
            $attempt = $this->forgot($email);
            $this->send($attempt)->assertStatus(202);
            $this->work();
            for ($i = 0; $i < 5; $i++) {
                $this->verify($attempt)->assertStatus(422)->assertJsonPath('code', 'invalid-code');
            }
            $this->verify($attempt)->assertStatus(429);
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_expired_attempt_and_expired_reset_proof_cannot_reset(): void
    {
        $this->user();
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        $this->travel(6)->minutes();
        $this->reset($attempt, $proof)->assertStatus(410);
        $this->travel(5)->minutes();
        $this->verify($attempt)->assertStatus(410);
        $this->assertTrue(Hash::check('ValidPass1!', User::first()->password));
    }

    public function test_cross_purpose_and_cross_account_material_cannot_be_reused(): void
    {
        $this->user();
        $this->user('other@example.com');
        $first = $this->forgot();
        $firstProof = $this->proof($first);
        $other = $this->forgot('other@example.com');
        $this->verify([...$first, 'attempt_token' => $other['attempt_token']])->assertStatus(404);
        $this->postJson('/api/auth/otp/verify', [...$first, 'purpose' => 'login', 'code' => $this->code])->assertStatus(404);
        $this->postJson('/api/auth/otp/send', $first)->assertStatus(404);
        $this->postJson('/api/auth/register', [...$first, 'verification_proof' => $firstProof])->assertStatus(404);
        $this->travel(61)->seconds();
        $this->proof($other);
        $this->reset($other, $firstProof)->assertStatus(404);
        $this->assertTrue(Hash::check('ValidPass1!', User::where('email', 'other@example.com')->first()->password));
    }

    public function test_password_rules_and_confirmation_do_not_consume_a_valid_proof(): void
    {
        $this->user();
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        $this->reset($attempt, $proof, 'weak')->assertStatus(422)->assertJsonPath('error_codes.password.0', 'weak-password');
        $this->postJson('/api/auth/password/reset', [...$attempt, 'reset_proof' => $proof,
            'password' => 'NewStrongPass1!', 'password_confirmation' => 'different'])->assertStatus(422);
        $this->assertSame('verified', DB::table('auth_attempts')->value('status'));
        $this->reset($attempt, $proof)->assertOk();
    }

    public function test_provider_failure_remains_neutral_and_cannot_produce_a_reset_proof(): void
    {
        $this->user();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Simulated provider failure'));
        $attempt = $this->forgot();
        $this->send($attempt)->assertStatus(202)->assertJsonPath('code', 'recovery-request-accepted');
        $this->work();
        $this->verify($attempt)->assertStatus(422)->assertJsonPath('code', 'invalid-code');
        $this->assertNull(DB::table('auth_attempts')->value('proof_hash'));
    }

    public function test_resend_cooldown_and_forgot_rate_limits_are_preserved(): void
    {
        $this->user();
        $attempt = $this->forgot();
        $this->send($attempt)->assertStatus(202);
        $this->send($attempt)->assertStatus(429);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->travel(61)->seconds();
        $this->send($attempt)->assertStatus(202);
        for ($i = 0; $i < 4; $i++) {
            $this->forgot();
        }
        $this->postJson('/api/auth/password/forgot', ['email' => ' AMINA@EXAMPLE.COM ', 'phone_number' => '+201012345678'])->assertStatus(429);
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_password_change_after_verification_invalidates_reset_proof(): void
    {
        $user = $this->user();
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        $user->password = 'AnotherPass1!';
        $user->save();
        $this->reset($attempt, $proof)->assertStatus(409);
        $this->assertTrue(Hash::check('AnotherPass1!', $user->fresh()->password));
    }

    public function test_duplicate_job_delivery_does_not_send_twice(): void
    {
        $this->user();
        $attempt = $this->forgot();
        $this->send($attempt)->assertStatus(202);
        $this->work();
        app(PasswordRecovery::class)->deliver($attempt['attempt_id'], 1);
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_failed_token_revocation_rolls_back_password_and_proof_consumption(): void
    {
        $user = $this->user();
        $user->createToken('existing');
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        DB::unprepared("CREATE FUNCTION reject_token_delete() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test rollback''; END'; CREATE TRIGGER reject_token_delete BEFORE DELETE ON personal_access_tokens FOR EACH ROW EXECUTE FUNCTION reject_token_delete()");
        $this->reset($attempt, $proof)->assertStatus(503)->assertJsonPath('code', 'otp-service-unavailable');
        $this->assertTrue(Hash::check('ValidPass1!', $user->fresh()->password));
        $this->assertSame('verified', DB::table('auth_attempts')->where('id', $attempt['attempt_id'])->value('status'));
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        DB::unprepared('DROP TRIGGER reject_token_delete ON personal_access_tokens; DROP FUNCTION reject_token_delete()');
        $this->reset($attempt, $proof)->assertOk();
    }

    public function test_expired_provider_challenge_and_queued_account_change_fail_closed(): void
    {
        $user = $this->user();
        $attempt = $this->forgot();
        $this->send($attempt)->assertStatus(202);
        $user->password = 'AnotherPass1!';
        $user->save();
        $this->work();
        Http::assertNothingSent();
        $this->verify($attempt)->assertStatus(422);
        $this->travel(61)->seconds();
        $second = $this->forgot();
        $this->send($second)->assertStatus(202);
        $this->work();
        DB::table('auth_attempts')->where('id', $second['attempt_id'])->update(['challenge_expires_at' => now()->subSecond()]);
        $this->verify($second)->assertStatus(422);
        $this->assertNull(DB::table('auth_attempts')->where('id', $second['attempt_id'])->value('proof_hash'));
    }

    public function test_login_challenges_cannot_enter_recovery_and_queue_must_share_the_database(): void
    {
        $this->user();
        $login = $this->postJson('/api/auth/login/start', ['email' => 'amina@example.com', 'password' => 'ValidPass1!'])->assertCreated();
        $material = ['attempt_id' => $login->json('data.attempt_id'), 'attempt_token' => $login->json('data.attempt_token')];
        $this->send($material)->assertStatus(404);
        $this->verify($material)->assertStatus(404);
        $this->reset($material, str_repeat('a', 64))->assertStatus(404);
        $attempt = $this->forgot();
        config(['queue.connections.database.connection' => 'other-database']);
        $this->send($attempt)->assertStatus(503);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_oversized_bcrypt_password_is_rejected_without_consuming_proof(): void
    {
        $this->user();
        $attempt = $this->forgot();
        $proof = $this->proof($attempt);
        $this->reset($attempt, $proof, str_repeat('Aa1!', 19))->assertStatus(422)->assertJsonPath('code', 'weak-password');
        $this->reset($attempt, $proof)->assertOk();
    }

    private function user(string $email = 'amina@example.com', bool $verified = true): User
    {
        $user = new User(['username' => 'User_'.substr(hash('sha256', $email), 0, 6), 'first_name' => 'Amina', 'last_name' => 'Hassan',
            'email' => $email, 'password' => 'ValidPass1!', 'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'Cairo', 'address' => 'Test address']);
        $user->role = UserRole::Patient;
        if ($verified) {
            $user->phone_verified_at = now();
            $user->phone_verified_hash = hash_hmac('sha256', $user->phone_number, config('app.key'));
        }
        $user->save();

        return $user;
    }

    private function forgot(string $email = 'amina@example.com', string $phone = '+201012345678'): array
    {
        $result = $this->postJson('/api/auth/password/forgot', ['email' => $email, 'phone_number' => $phone])->assertStatus(202);

        return ['attempt_id' => $result->json('data.attempt_id'), 'attempt_token' => $result->json('data.attempt_token')];
    }

    private function send(array $attempt)
    {
        return $this->postJson('/api/auth/password/otp/send', $attempt);
    }

    private function verify(array $attempt)
    {
        return $this->postJson('/api/auth/password/otp/verify', [...$attempt, 'code' => $this->code]);
    }

    private function reset(array $attempt, string $proof, string $password = 'NewStrongPass1!')
    {
        return $this->postJson('/api/auth/password/reset', [...$attempt, 'reset_proof' => $proof, 'password' => $password, 'password_confirmation' => $password]);
    }

    private function proof(array $attempt): string
    {
        $this->send($attempt)->assertStatus(202);
        $this->work();
        $response = $this->verify($attempt)->assertOk()->assertJsonPath('data.authenticated', false);

        return $response->json('data.reset_proof');
    }

    private function work(): void
    {
        $job = Queue::connection('database')->pop('auth-recovery');
        $this->assertNotNull($job);
        $job->fire();
    }

    private function loginToken(): string
    {
        $start = $this->postJson('/api/auth/login/start', ['email' => 'amina@example.com', 'password' => 'ValidPass1!'])->assertCreated();
        $attempt = ['attempt_id' => $start->json('data.attempt_id'), 'attempt_token' => $start->json('data.attempt_token')];
        $this->postJson('/api/auth/otp/send', $attempt)->assertStatus(202);

        return $this->postJson('/api/auth/otp/verify', [...$attempt, 'purpose' => 'login', 'code' => $this->code])->assertOk()->json('data.token');
    }

    private function provider(bool $verified = true): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_ends_with($request->url(), '/verify')
            ? Http::response(['verified' => $verified], $verified ? 200 : 422)
            : Http::response(['id' => bin2hex(random_bytes(16)), 'expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s')], 202));
    }
}
