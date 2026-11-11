<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class PostgresTestDatabase
{
    public const string DATABASE = 'sanad_test_db';

    public static function assertSafeConfiguration(array $connection, string $environment, bool $cached): void
    {
        if ($environment !== 'testing' || $cached
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['database'] ?? null) !== self::DATABASE
            || ($connection['username'] ?? null) !== 'sanad_app'
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! empty($connection['url']) || ! empty($connection['read']) || ! empty($connection['write'])) {
            throw new RuntimeException('Unsafe PostgreSQL test configuration: require uncached testing environment, local sanad_test_db, sanad_app and no URL/read/write overrides.');
        }
    }

    public static function assertSafeServer(array $server): void
    {
        if (($server['database'] ?? null) !== self::DATABASE
            || ($server['username'] ?? null) !== 'sanad_app'
            || ! in_array($server['server_address'] ?? null, ['127.0.0.1', '::1'], true)
            || (int) ($server['can_connect'] ?? 0) !== 1
            || (int) ($server['can_create'] ?? 0) !== 1) {
            throw new RuntimeException('PostgreSQL test preflight failed: require actual local sanad_test_db connection as sanad_app with CONNECT and CREATE SCHEMA permissions. No schema changes are allowed.');
        }
    }

    /** Read-only preflight; must run before transactions, schemas or migrations. */
    public static function verifyConnection(Connection $connection): array
    {
        $server = self::inspectConnection($connection);
        self::assertSafeServer($server);

        return $server;
    }

    /** Only non-secret connection identity and privilege diagnostics. */
    public static function inspectConnection(Connection $connection): array
    {
        return (array) $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database, current_user AS username,
                host(inet_server_addr()) AS server_address,
                current_setting('server_version') AS version,
                current_setting('TimeZone') AS timezone,
                has_database_privilege(current_user, current_database(), 'CONNECT')::int AS can_connect,
                has_database_privilege(current_user, current_database(), 'CREATE')::int AS can_create,
                (SELECT count(*) FROM pg_namespace WHERE nspname LIKE 'sanad_phase1_%' OR nspname LIKE 'sanad_otp_race_%' OR nspname LIKE 'sanad_link_race_%') AS test_schema_count
            SQL);
    }
}
