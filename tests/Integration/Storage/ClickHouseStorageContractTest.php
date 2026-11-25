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
        // The same entries again, as the buffer resends them after a timeout
        // whose insert actually succeeded.
        $this->storage->saveMany(array_values($this->seeded));

        $result = $this->storage->search(SearchFilters::none());

        self::assertSame(5, $result['total']);
        self::assertCount(5, $result['entries']);
    }

    #[Test]
    public function the_async_batch_writer_stores_entries_the_storage_reads_back(): void
    {
        $client = ClickHouseTestDatabase::client();
        ClickHouseTestDatabase::truncate($client);

        $loop    = \React\EventLoop\Loop::get();
        $writer  = new \LogService\Ingest\ClickHouseBatchWriter($client, new \React\Http\Browser(null, $loop));
        $settled = null;
        $writer->write(array_values($this->seeded))->then(
            function () use (&$settled, $loop) { $settled = true; $loop->stop(); },
            function (\Throwable $e) use (&$settled, $loop) { $settled = $e; $loop->stop(); },
        );
        $guard = $loop->addTimer(30, static fn() => $loop->stop());
        $loop->run();
        $loop->cancelTimer($guard);

        self::assertTrue($settled, $settled instanceof \Throwable ? $settled->getMessage() : 'write never settled');
        foreach ($this->seeded as $suffix => $entry) {
            self::assertSame($entry->toArray(), $this->storage->findById($entry->id)?->toArray(), "entry {$suffix}");
        }
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
