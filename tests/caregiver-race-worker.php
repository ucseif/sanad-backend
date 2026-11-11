<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PostgresTestDatabase;

require __DIR__.'/../vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/\Asanad_link_race_[a-f0-9]{16}\z/', $input['schema'] ?? '')
        || ! preg_match('~\A/api/caregiver-links(?:/[a-f0-9-]{36}/(?:accept|decline|cancel|revoke))?\z~', $input['path'] ?? '')) {
        throw new RuntimeException('Invalid worker input.');
    }
    $app = require __DIR__.'/../bootstrap/app.php';
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration forbidden.');
    }
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    PostgresTestDatabase::assertSafeConfiguration(config('database.connections.pgsql'), $app->environment(), false);
    $connection = $app['db']->connection('pgsql');
    PostgresTestDatabase::verifyConnection($connection);
    $connection->statement('SET search_path TO "'.$input['schema'].'"');
    if ($connection->selectOne('SELECT current_schema() AS name')->name !== $input['schema']) {
        throw new RuntimeException('Schema mismatch.');
    }
    Http::preventStrayRequests();
    echo "READY\n";
    flush();
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create($input['path'], 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($input['body'], JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'code' => $body['code'] ?? null], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Isolated caregiver worker failed; details suppressed.\n");
    exit(1);
}
