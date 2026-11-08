<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PostgresTestDatabase;

abstract class TestCase extends BaseTestCase
{
    private bool $testTransactionStarted = false;

    public function createApplication()
    {
        $app = parent::createApplication();

        // createApplication returns BEFORE RefreshDatabase and other setup traits.
        $this->assertTrue($app->environment('testing'));
        $this->assertFalse($app->configurationIsCached(), 'Remove cached configuration before testing.');
        $this->assertSame('.env.testing', $app->environmentFile(), 'Configure .env.testing; never fall back to development credentials/configuration.');
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('sanad_test_db', config('database.connections.pgsql.database'));

        $connection = config('database.connections.pgsql');
        $this->assertSame('sanad_test_db', env('SANAD_POSTGRES_TEST_DATABASE'));
        PostgresTestDatabase::assertSafeConfiguration($connection, $app->environment(), $app->configurationIsCached());
        PostgresTestDatabase::verifyConnection($app['db']->connection('pgsql'));

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        PostgresTestDatabase::verifyConnection(DB::connection());
        DB::beginTransaction();
        $this->testTransactionStarted = true;
        $schema = 'sanad_phase1_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::statement('SET LOCAL search_path TO "'.$schema.'"');
        $this->assertSame($schema, DB::selectOne('SELECT current_schema() AS name')->name);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->testTransactionStarted) {
                DB::connection('pgsql')->rollBack();
            }
            DB::purge('pgsql');
        } finally {
            parent::tearDown();
        }
    }
}
