<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Storage\ClickHouseStorage;
use LogService\Storage\SearchFilters;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\ClickHouseTestDatabase;
use PHPUnit\Framework\Attributes\Test;

/** Skipped unless TEST_CLICKHOUSE_URL is set; see ClickHouseTestDatabase. */
final class ClickHouseStorageContractTest extends StorageContractTestCase
{
    private ?ClickHouseStorage $storage = null;

    protected function setUp(): void
    {
        $client = ClickHouseTestDatabase::client();
        if ($client === null) {
            self::markTestSkipped('TEST_CLICKHOUSE_URL not set; skipping ClickHouse integration tests.');
        }
        ClickHouseTestDatabase::truncate($client);
        $this->storage = new ClickHouseStorage($client);

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return $this->storage;
    }

    #[Test]
    public function a_retried_batch_is_returned_once(): void
    {
        // The same entries again, as after a timeout whose insert actually succeeded.
        $all = $this->storage->search(SearchFilters::none())['entries'];
        $this->storage->saveMany(array_map(\LogService\Models\LogEntry::fromArray(...), $all));

        $result = $this->storage->search(SearchFilters::none());

        self::assertSame(5, $result['total']);
        self::assertCount(5, $result['entries']);
    }

    #[Test]
    public function reports_itself_reachable(): void
    {
        $health = $this->storage->health();

        self::assertSame('clickhouse', $health['type']);
        self::assertTrue($health['reachable']);
        self::assertIsFloat($health['latency_ms']);
    }
}
