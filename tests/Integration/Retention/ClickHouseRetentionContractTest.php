<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Retention;

use LogService\Retention\ClickHouseRetentionEngine;
use LogService\Retention\ClickHouseRetentionVolumeAnalyzer;
use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouseStorage;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\ClickHouseTestDatabase;
use PHPUnit\Framework\Attributes\Test;

/** Skipped unless TEST_CLICKHOUSE_URL is set; see ClickHouseTestDatabase. */
final class ClickHouseRetentionContractTest extends RetentionContractTestCase
{
    private ?ClickHouseClient $client = null;

    protected function setUp(): void
    {
        $this->client = ClickHouseTestDatabase::client();
        if ($this->client === null) {
            self::markTestSkipped('TEST_CLICKHOUSE_URL not set; skipping ClickHouse integration tests.');
        }
        ClickHouseTestDatabase::truncate($this->client);

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return new ClickHouseStorage($this->client);
    }

    protected function engine(): RetentionEngineInterface
    {
        return new ClickHouseRetentionEngine($this->client);
    }

    protected function pruner(): PrunerInterface
    {
        return new ClickHouseRetentionEngine($this->client);
    }

    protected function analyzer(): RetentionVolumeAnalyzerInterface
    {
        return new ClickHouseRetentionVolumeAnalyzer($this->client);
    }

    #[Test]
    public function an_age_only_policy_drops_whole_day_partitions(): void
    {
        $tenDaysAgo = (new \DateTimeImmutable('today -10 days', new \DateTimeZone('UTC')))->format('Ymd');
        self::assertContains($tenDaysAgo, $this->partitions());

        $this->engine()->purge(RetentionPolicy::fromArray(['name' => 'week', 'older_than_days' => 7]));

        self::assertNotContains($tenDaysAgo, $this->partitions(), 'the old day is dropped, not just masked');
    }

    #[Test]
    public function the_volume_report_reads_the_real_table_size(): void
    {
        $storage = $this->analyzer()->analyze([], days: 1)->toArray()['storage'];

        self::assertSame('log_entries', $storage['table']);
        self::assertSame('ReplacingMergeTree', $storage['engine']);
        self::assertGreaterThan(0, $storage['data_bytes']);
        self::assertGreaterThan(0, $storage['index_bytes']);
        self::assertSame(6, $storage['rows_estimate']);
    }

    /** @return list<string> */
    private function partitions(): array
    {
        return array_column($this->client->select(
            "SELECT DISTINCT partition FROM system.parts WHERE database = currentDatabase() AND table = 'log_entries' AND active",
        ), 'partition');
    }
}
