<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use App\Application\Medication\DoseMaterializer;
use App\Enums\UserRole;
use Carbon\CarbonImmutable as Time;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CaregiverLinkTables;
use Tests\Support\MedicationTables;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

class MedicationConcurrencyTest extends TestCase
{
    #[DataProvider('races')]
    public function test_real_http_workers_serialize_safely(string $mode): void
    {
        $config = config('database.connections.pgsql');
        PostgresTestDatabase::assertSafeConfiguration($config, $this->app->environment(), $this->app->configurationIsCached());
        config(['database.connections.medication_race' => $config]);
        $connection = DB::connection('medication_race');
        PostgresTestDatabase::verifyConnection($connection);
        // Reuse an existing guarded prefix; never weaken or bypass the schema guard.
        $schema = 'sanad_link_race_'.bin2hex(random_bytes(8));
        $workers = [];
        $created = false;
        try {
            $connection->beginTransaction();
            $connection->statement('CREATE SCHEMA "'.$schema.'"');
            $connection->statement('SET search_path TO "'.$schema.'"');
            $created = true;
            DB::setDefaultConnection('medication_race');
            MedicationTables::create();
            $patient = CaregiverLinkTables::user('RacePatient', UserRole::Patient);
            $caregiver = CaregiverLinkTables::user('RaceCaregiver', UserRole::Caregiver);
            Time::setTestNow(Time::parse('2026-11-11T06:00:00Z'));
            $link = app(CaregiverLinks::class)->invite($patient, $caregiver->username);
            app(CaregiverLinks::class)->transition($caregiver, $link->id, 'accept');
            $pt = $patient->createToken('isolated-race')->plainTextToken;
            $ct = $caregiver->createToken('isolated-race')->plainTextToken;
            $root = '/api/v1/patients/'.$patient->id;
            // Seed through the API with real Sanctum authentication.
            $kernel = $this->app->make(Kernel::class);
            $payload = ['prescription' => ['name' => 'Race example', 'dose_amount' => '1', 'dose_unit' => 'tablet', 'form' => 'tablet', 'relation_to_meals' => 'no_preference'],
                'schedule' => ['frequency' => 'once_daily', 'timezone' => 'UTC', 'start_date' => '2026-11-11', 'times' => ['08:00']]];
            $request = Request::create($root.'/medications', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$pt, 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
            ], json_encode($payload, JSON_THROW_ON_ERROR));
            $response = $kernel->handle($request);
            $this->assertSame(201, $response->getStatusCode());
            $med = json_decode($response->getContent(), true)['data']['medication']['id'];
            app(DoseMaterializer::class)->between($patient->id, Time::parse('2026-11-11'), Time::parse('2026-11-12'), Time::now('UTC'));
            $dose = $connection->table('medication_dose_occurrences')->value('id');
            $paths = [$root.'/medication-doses/'.$dose.'/taken', $root.'/medication-doses/'.$dose.'/skipped'];
            $methods = ['POST', 'POST'];
            $tokens = [$pt, $pt];
            $bodies = [['expected_version' => 1], ['expected_version' => 1]];
            $keys = [(string) Str::uuid(), (string) Str::uuid()];
            $clock = '2026-11-11T08:05:00Z';
            if ($mode === 'duplicate') {
                $paths[1] = $paths[0];
                $keys[1] = $keys[0];
            } elseif ($mode === 'late-missed') {
                $clock = '2026-11-11T08:30:00Z';
                $paths = [$root.'/medication-doses/'.$dose.'/late-intake', $root.'/medication-dashboard?date=2026-11-11&timezone=UTC'];
                $methods[1] = 'GET';
                $bodies[1] = [];
            } elseif ($mode === 'revoke-edit') {
                $paths = [$root.'/medications/'.$med, '/api/caregiver-links/'.$link->id.'/revoke'];
                $methods = ['PATCH', 'POST'];
                $tokens = [$ct, $pt];
                $bodies = [['expected_version' => 1, 'prescription' => ['name' => 'Caregiver edit']], []];
            } elseif ($mode === 'parallel-edits') {
                $paths = [$root.'/medications/'.$med, $root.'/medications/'.$med];
                $methods = ['PATCH', 'PATCH'];
                $tokens = [$pt, $ct];
                $bodies = [['expected_version' => 1, 'prescription' => ['name' => 'Patient edit']], ['expected_version' => 1, 'prescription' => ['name' => 'Caregiver edit']]];
            }
            $connection->commit();
            $connection->beginTransaction();
            $connection->table('users')->where('id', $patient->id)->lockForUpdate()->first();
            foreach ([0, 1] as $i) {
                $worker = new Process([PHP_BINARY, base_path('tests/medication-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'sanad_test_db', 'DB_URL' => '',
                    'SANAD_POSTGRES_TEST_DATABASE' => 'sanad_test_db', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null',
                ]);
                $worker->setTimeout(30)->setInput(json_encode(['schema' => $schema, 'path' => $paths[$i], 'method' => $methods[$i],
                    'body' => $bodies[$i], 'token' => $tokens[$i], 'key' => $keys[$i], 'now' => $clock], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            }
            $connection->commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Worker failed; secrets suppressed.');
                $lines = explode("\n", trim($worker->getOutput()));
                $statuses[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            if ($mode === 'revoke-edit') {
                $this->assertContains($statuses, [[200, 200], [200, 404]]);
                $this->assertSame('revoked', $connection->table('caregiver_links')->value('status'));
                $this->assertNull($connection->table('medications')->value('archived_at'));
            } else {
                $this->assertSame(in_array($mode, ['duplicate', 'late-missed'], true) ? [200, 200] : [200, 409], $statuses);
            }
            $this->assertSame($mode === 'late-missed' ? 1 : 0, $connection->table('medication_intake_events')->where('event_type', 'missed')->count());
            if (in_array($mode, ['duplicate', 'taken-skipped', 'late-missed'], true)) {
                $this->assertSame(1, $connection->table('medication_intake_events')->whereNotNull('actor_id')->count());
            }
        } finally {
            Time::setTestNow();
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            PostgresTestDatabase::verifyConnection($connection);
            $connection->statement('SET search_path TO public');
            if ($created && preg_match('/\Asanad_link_race_[a-f0-9]{16}\z/', $schema)) {
                $connection->statement('DROP SCHEMA "'.$schema.'" CASCADE');
            }
            DB::setDefaultConnection('pgsql');
            DB::purge('medication_race');
        }
    }

    public static function races(): array
    {
        return [['taken-skipped'], ['duplicate'], ['late-missed'], ['revoke-edit'], ['parallel-edits']];
    }
}
