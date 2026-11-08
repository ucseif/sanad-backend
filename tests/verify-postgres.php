<?php

// Standalone read-only preflight, using the same safety guard as integration tests.
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\PostgresTestDatabase;

require __DIR__.'/../vendor/autoload.php';

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration is forbidden for PostgreSQL test preflight.');
    }
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    if ($app->environmentFile() !== '.env.testing') {
        throw new RuntimeException('A dedicated .env.testing file is required.');
    }
    $connection = config('database.connections.pgsql');
    PostgresTestDatabase::assertSafeConfiguration($connection, $app->environment(), $app->configurationIsCached());
    $server = PostgresTestDatabase::inspectConnection($app['db']->connection('pgsql'));
    echo json_encode($server, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    PostgresTestDatabase::assertSafeServer($server);
} catch (Throwable $error) {
    // Laravel's console exception handler may otherwise finish a standalone
    // script with status 0. Never let automation mistake a refusal for success.
    fwrite(STDERR, "PostgreSQL preflight refused; verify target identity and CONNECT/CREATE permissions. No schema changes were performed.\n");
    exit(1);
}
