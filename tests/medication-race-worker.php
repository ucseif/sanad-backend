<?php

use Carbon\CarbonImmutable as Time;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PostgresTestDatabase;

require __DIR__.'/../vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/\Asanad_link_race_[a-f0-9]{16}\z/', $input['schema'] ?? '')
        || ! preg_match('~\A/api/(?:v1/patients/[1-9][0-9]*/(?:medications/[a-f0-9-]{36}|medication-doses/[a-f0-9-]{36}/(?:taken|skipped|late-intake)|medication-dashboard)|caregiver-links/[a-f0-9-]{36}/revoke)\z~', parse_url($input['path'] ?? '', PHP_URL_PATH) ?? '')
        || ! in_array($input['method'] ?? '', ['GET', 'POST', 'PATCH'], true)) {
        throw new RuntimeException('Invalid isolated worker input.');
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
    Time::setTestNow(Time::parse($input['now']));
    Http::preventStrayRequests();
    echo "READY\n";
    flush();
    $request = Request::create($input['path'], $input['method'], [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IDEMPOTENCY_KEY' => $input['key'], 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($input['body'], JSON_THROW_ON_ERROR));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'code' => json_decode($response->getContent(), true)['code'] ?? null], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable) {
    fwrite(STDERR, "Isolated medication worker failed; details suppressed.\n");
    exit(1);
}
