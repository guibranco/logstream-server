<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Storage\ClickHouseStorage;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\ClickHouseTestDatabase;
use PHPUnit\Framework\Attributes\Test;

/** Context filters through the Router, on ClickHouse. Skipped unless TEST_CLICKHOUSE_URL is set. */
final class ClickHouseStorageContextSearchTest extends ContextSearchTestCase
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
    public function search_matches_whole_words_only(): void
    {
        // Like MariaDB's FULLTEXT: "card_declined" is one word, so "card" only hits BB.
        self::assertSame(['BB'], $this->searchIds('context_search=card'));
    }
}
