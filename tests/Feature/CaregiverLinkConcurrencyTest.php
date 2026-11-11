<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use App\Enums\UserRole;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CaregiverLinkTables;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

class CaregiverLinkConcurrencyTest extends TestCase
{
    #[DataProvider('races')]
    public function test_separate_requests_serialize_on_the_patient(string $mode): void
    {
        $configuration = config('database.connections.pgsql');
        PostgresTestDatabase::assertSafeConfiguration($configuration, $this->app->environment(), $this->app->configurationIsCached());
        config(['database.connections.link_race' => $configuration]);
        $connection = DB::connection('link_race');
        PostgresTestDatabase::verifyConnection($connection);
        $schema = 'sanad_link_race_'.bin2hex(random_bytes(8));
        $workers = [];
        $created = false;
        try {
            $connection->beginTransaction();
            $connection->statement('CREATE SCHEMA "'.$schema.'"');
            $connection->statement('SET search_path TO "'.$schema.'"');
            $created = true;
            DB::setDefaultConnection('link_race');
            CaregiverLinkTables::create();
            $p = CaregiverLinkTables::user('Patient1', UserRole::Patient);
            $carers = [];
            for ($n = 1; $n <= 4; $n++) {
                $carers[] = CaregiverLinkTables::user('Caregiver'.$n, UserRole::Caregiver);
            }
            $flow = app(CaregiverLinks::class);
            $patientToken = $p->createToken('race', ['*'], now()->addHour())->plainTextToken;
            $caregiverToken = $carers[0]->createToken('race', ['*'], now()->addHour())->plainTextToken;
            $path = '/api/caregiver-links';
            $bodies = [['caregiver_username' => 'Caregiver1'], ['caregiver_username' => 'Caregiver1']];
            $paths = [$path, $path];
            $tokens = [$patientToken, $patientToken];
            if ($mode === 'last-slot') {
                $flow->invite($p, 'Caregiver1');
                $bodies = [['caregiver_username' => 'Caregiver2'], ['caregiver_username' => 'Caregiver3']];
            } elseif (in_array($mode, ['accept-cancel', 'double-accept', 'double-revoke'], true)) {
                $link = $flow->invite($p, 'Caregiver1');
                if ($mode === 'double-revoke') {
                    $flow->transition($carers[0], $link->id, 'accept');
                }
                $paths = ["$path/$link->id/".($mode === 'double-revoke' ? 'revoke' : 'accept'),
                    "$path/$link->id/".match ($mode) {
                        'accept-cancel' => 'cancel', 'double-revoke' => 'revoke', default => 'accept'
                    }];
                $tokens = [$caregiverToken, $mode === 'accept-cancel' ? $patientToken : $caregiverToken];
                $bodies = [[], []];
            }
            $connection->commit();
            // Hold the shared serialization row until both HTTP workers have booted.
            $connection->beginTransaction();
            $connection->table('users')->where('id', $p->id)->lockForUpdate()->first();
            foreach ([0, 1] as $index) {
                $worker = new Process([PHP_BINARY, base_path('tests/caregiver-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'sanad_test_db', 'DB_URL' => '',
                    'SANAD_POSTGRES_TEST_DATABASE' => 'sanad_test_db', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null',
                ]);
                $worker->setTimeout(30)->setInput(json_encode(['schema' => $schema, 'path' => $paths[$index], 'body' => $bodies[$index], 'token' => $tokens[$index]], JSON_THROW_ON_ERROR));
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), 'Worker must boot before releasing the patient lock.');
            }
            $connection->commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Worker failed; secrets suppressed.');
                $lines = explode("\n", trim($worker->getOutput()));
                $statuses[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame(in_array($mode, ['duplicate', 'last-slot'], true) ? [201, 409] : [200, 409], $statuses);
            $live = $connection->table('caregiver_links')->whereIn('status', ['pending', 'active'])->count();
            $this->assertSame(match ($mode) {
                'last-slot' => 2, 'double-revoke' => 0, 'accept-cancel' => $connection->table('caregiver_links')->where('status', 'active')->count(), default => 1
            }, $live);
            $this->assertSame(5, $connection->table('users')->count());
        } finally {
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
                $connection->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            }
            DB::setDefaultConnection('pgsql');
            DB::purge('link_race');
        }
    }

    public static function races(): array
    {
        return [['duplicate'], ['last-slot'], ['accept-cancel'], ['double-accept'], ['double-revoke']];
    }
}
