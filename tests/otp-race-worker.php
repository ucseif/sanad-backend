<?php

// Secrets arrive through stdin, never process arguments or output. Test use only.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PostgresTestDatabase;

require __DIR__.'/../vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/\Asanad_otp_race_[a-f0-9]{16}\z/', $input['schema'] ?? '')
        || ! in_array($input['path'] ?? '', ['/api/auth/register', '/api/auth/otp/verify', '/api/auth/password/reset'], true)) {
        throw new RuntimeException('Invalid test worker input.');
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
    config(['services.wexora.api_key' => 'test-key', 'services.wexora.session_id' => 'test-session']);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (! str_ends_with($request->url(), '/verify')) {
            throw new RuntimeException('Unexpected worker HTTP.');
        }
        usleep(300000); // Keep the first verifier's row lock while its peer arrives.

        return Http::response(['verified' => true], 200);
    });
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create($input['path'], 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($input['body'], JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'token_issued' => isset($body['data']['token']),
        'provider_requests' => Http::recorded()->count()], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Isolated OTP race worker failed; details suppressed.\n");
    exit(1);
}
