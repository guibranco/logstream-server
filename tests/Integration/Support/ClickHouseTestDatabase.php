<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Support;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseSettings;

/**
 * A ClickHouse for integration tests, from TEST_CLICKHOUSE_URL and the usual
 * CLICKHOUSE_DATABASE / CLICKHOUSE_USER / CLICKHOUSE_PASSWORD. The schema must
 * already exist (CI runs Tools/clickhouse-migration.sh first):
 *
 *   docker compose --profile clickhouse up -d clickhouse
 *   export CLICKHOUSE_USER=logstream CLICKHOUSE_PASSWORD=test
 *   bash Tools/clickhouse-migration.sh
 *   TEST_CLICKHOUSE_URL=http://127.0.0.1:8123 vendor/bin/phpunit --testsuite integration
 *
 * WARNING: log_entries is truncated before every test.
 */
final class ClickHouseTestDatabase
{
    /** Null when TEST_CLICKHOUSE_URL is not set (tests should skip). */
    public static function client(): ?ClickHouseClient
    {
        $url = getenv('TEST_CLICKHOUSE_URL');
        if (!$url) {
            return null;
        }

        return new ClickHouseClient(ClickHouseSettings::fromEnv([
            'CLICKHOUSE_URL'      => $url,
            'CLICKHOUSE_DATABASE' => getenv('CLICKHOUSE_DATABASE') ?: 'logstream',
            'CLICKHOUSE_USER'     => getenv('CLICKHOUSE_USER') ?: 'default',
            'CLICKHOUSE_PASSWORD' => getenv('CLICKHOUSE_PASSWORD') ?: '',
        ]));
    }

    public static function truncate(ClickHouseClient $client): void
    {
        $client->request('TRUNCATE TABLE log_entries');
    }
}
