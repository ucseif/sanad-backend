<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;

class PostgresTestDatabaseTest extends TestCase
{
    public function test_exact_database_safety_guard_exists(): void
    {
        $this->assertTrue(class_exists(PostgresTestDatabase::class));
    }

    public function test_accepts_only_the_intended_local_test_configuration(): void
    {
        PostgresTestDatabase::assertSafeConfiguration($this->configuration(), 'testing', false);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_rejects_unsafe_configuration_before_connecting(array $override, string $environment, bool $cached): void
    {
        $this->expectException(RuntimeException::class);
        PostgresTestDatabase::assertSafeConfiguration([...$this->configuration(), ...$override], $environment, $cached);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'development database' => [['database' => 'sanad_db'], 'testing', false],
            'another test database' => [['database' => 'sanad_test'], 'testing', false],
            'missing database' => [['database' => null], 'testing', false],
            'remote host' => [['host' => 'example.com'], 'testing', false],
            'host list' => [['host' => ['127.0.0.1', 'example.com']], 'testing', false],
            'URL override' => [['url' => 'pgsql://localhost/sanad_db'], 'testing', false],
            'write override' => [['write' => ['database' => 'sanad_db']], 'testing', false],
            'read override' => [['read' => ['database' => 'sanad_db']], 'testing', false],
            'wrong driver' => [['driver' => 'sqlite'], 'testing', false],
            'unexpected account' => [['username' => 'postgres'], 'testing', false],
            'development environment' => [[], 'local', false],
            'cached configuration' => [[], 'testing', true],
        ];
    }

    #[DataProvider('unsafeServers')]
    public function test_rejects_wrong_server_identity_or_insufficient_permissions(array $override): void
    {
        $this->expectException(RuntimeException::class);
        PostgresTestDatabase::assertSafeServer([...$this->server(), ...$override]);
    }

    public static function unsafeServers(): array
    {
        return [
            'actual development database' => [['database' => 'sanad_db']],
            'actual unexpected account' => [['username' => 'postgres']],
            'actual remote server' => [['server_address' => '192.0.2.1']],
            'cannot connect' => [['can_connect' => 0]],
            'cannot create schemas' => [['can_create' => 0]],
        ];
    }

    public function test_accepts_verified_server_identity_with_required_permissions(): void
    {
        PostgresTestDatabase::assertSafeServer($this->server());
        $this->addToAssertionCount(1);
    }

    private function configuration(): array
    {
        return ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432',
            'database' => 'sanad_test_db', 'username' => 'sanad_app', 'url' => null];
    }

    private function server(): array
    {
        return ['database' => 'sanad_test_db', 'username' => 'sanad_app',
            'server_address' => '127.0.0.1', 'can_connect' => 1, 'can_create' => 1];
    }
}
