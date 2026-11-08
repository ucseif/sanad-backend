<?php

namespace Tests\Feature;

use App\Application\Auth\OtpAuthentication;
use App\Application\Auth\PasswordRecovery;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\UserTable;
use Tests\TestCase;

class OtpConcurrencyTest extends TestCase
{
    #[DataProvider('raceModes')]
    public function test_parallel_completion_issues_exactly_one_token(string $purpose): void
    {
        $configuration = config('database.connections.pgsql');
        PostgresTestDatabase::assertSafeConfiguration($configuration, $this->app->environment(), $this->app->configurationIsCached());
        config(['database.connections.otp_race' => $configuration]);
        $connection = DB::connection('otp_race');
        PostgresTestDatabase::verifyConnection($connection);
        $schema = 'sanad_otp_race_'.bin2hex(random_bytes(8));
        $workers = [];
        $created = false;
        $flowPurpose = $purpose === 'login' ? 'login' : 'signup';
        try {
            $connection->beginTransaction();
            $connection->statement('CREATE SCHEMA "'.$schema.'"');
            $connection->statement('SET search_path TO "'.$schema.'"');
            $created = true;
            DB::setDefaultConnection('otp_race');
            UserTable::create();
            config(['services.wexora.api_key' => 'test-key', 'services.wexora.session_id' => 'test-session']);
            Http::fake(fn ($request) => str_ends_with($request->url(), '/verify')
                ? Http::response(['verified' => true], 200)
                : Http::response(['id' => bin2hex(random_bytes(16)), 'expires_at' => now()->addMinutes(5)->format('Y-m-d H:i:s')], 202));
            $registration = ['username' => 'Race_1', 'first_name' => 'Race', 'last_name' => 'Test', 'email' => 'race@example.com',
                'password' => 'ValidPass1!', 'password_confirmation' => 'ValidPass1!', 'role' => 'patient',
                'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'Cairo', 'address' => 'Test address'];
            if (in_array($purpose, ['login', 'password-reset'], true)) {
                $user = new User($registration);
                $user->role = UserRole::Patient;
                if ($purpose === 'password-reset') {
                    $user->phone_verified_at = now();
                    $user->phone_verified_hash = app(OtpAuthentication::class)->phoneHash($user->phone_number);
                }
                $user->save();
                $start = $purpose === 'password-reset'
                    ? $this->postJson('/api/auth/password/forgot', ['email' => $registration['email']])->assertStatus(202)
                    : $this->postJson('/api/auth/login/start', ['email' => $registration['email'], 'password' => $registration['password']])->assertCreated();
            } else {
                $start = $this->postJson('/api/auth/signup/start', $registration)->assertCreated();
            }
            $attempt = ['attempt_id' => $start->json('data.attempt_id'), 'attempt_token' => $start->json('data.attempt_token')];
            if ($purpose === 'password-reset') {
                $this->postJson('/api/auth/password/otp/send', $attempt)->assertStatus(202);
                app(PasswordRecovery::class)->deliver($attempt['attempt_id'], 1);
                $proof = $this->postJson('/api/auth/password/otp/verify', [...$attempt, 'code' => (string) random_int(100000, 999999)])->assertOk()->json('data.reset_proof');
                $user->createToken('revoke-on-reset');
                $path = '/api/auth/password/reset';
                $body = [...$attempt, 'reset_proof' => $proof, 'password' => 'NewStrongPass1!', 'password_confirmation' => 'NewStrongPass1!'];
            } else {
                $this->postJson('/api/auth/otp/send', $attempt)->assertStatus(202);
                $body = [...$attempt, 'purpose' => $flowPurpose, 'code' => (string) random_int(100000, 999999)];
                $path = '/api/auth/otp/verify';
                if ($flowPurpose === 'signup') {
                    $proof = $this->postJson($path, $body)->assertOk()->json('data.verification_proof');
                    $path = '/api/auth/register';
                    $body = [...$attempt, 'verification_proof' => $proof];
                }
            }
            $bodies = [$body, $body];
            if ($purpose === 'duplicate-signup') {
                $other = $this->postJson('/api/auth/signup/start', [...$registration, 'phone_number' => '+15551234567'])->assertCreated();
                $otherAttempt = ['attempt_id' => $other->json('data.attempt_id'), 'attempt_token' => $other->json('data.attempt_token')];
                $this->postJson('/api/auth/otp/send', $otherAttempt)->assertStatus(202);
                $otherProof = $this->postJson('/api/auth/otp/verify', [...$otherAttempt, 'purpose' => 'signup',
                    'code' => (string) random_int(100000, 999999)])->assertOk()->json('data.verification_proof');
                $bodies[1] = [...$otherAttempt, 'verification_proof' => $otherProof];
            }
            $connection->commit(); // Only this new test schema becomes visible to peer processes.
            foreach ([1, 2] as $number) {
                $process = new Process([PHP_BINARY, base_path('tests/otp-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'sanad_test_db', 'DB_URL' => '',
                    'SANAD_POSTGRES_TEST_DATABASE' => 'sanad_test_db', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                    'LOG_CHANNEL' => 'null',
                ]);
                $process->setTimeout(30)->setInput(json_encode(['schema' => $schema, 'path' => $path, 'body' => $bodies[$number - 1]], JSON_THROW_ON_ERROR));
                $process->start();
                $workers[] = $process;
            }
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Worker failed; authentication details suppressed.');
                $results[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(match ($purpose) {
                'login', 'password-reset' => [200, 409], 'duplicate-signup' => [201, 422], default => [201, 409]
            }, $statuses);
            $this->assertSame($purpose === 'password-reset' ? 0 : 1, count(array_filter(array_column($results, 'token_issued'))));
            $this->assertSame($purpose === 'login' ? 1 : 0, array_sum(array_column($results, 'provider_requests')));
            $this->assertSame(1, $connection->table('users')->count());
            $this->assertSame($purpose === 'password-reset' ? 0 : 1, $connection->table('personal_access_tokens')->count());
            if ($purpose === 'password-reset') {
                $this->assertTrue(Hash::check('NewStrongPass1!', $connection->table('users')->value('password')));
            }
            $this->assertSame(1, $connection->table('auth_attempts')->where('status', 'completed')->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
                $created = false;
            }
            PostgresTestDatabase::verifyConnection($connection);
            $connection->statement('SET search_path TO public');
            if ($created && preg_match('/\Asanad_otp_race_[a-f0-9]{16}\z/', $schema)) {
                // Cleanup only the isolated namespace created above, never public/user tables.
                $connection->statement('DROP SCHEMA "'.$schema.'" CASCADE');
            }
            DB::setDefaultConnection('pgsql');
            DB::purge('otp_race');
        }
    }

    public static function raceModes(): array
    {
        return [['login'], ['signup'], ['duplicate-signup'], ['password-reset']];
    }
}
