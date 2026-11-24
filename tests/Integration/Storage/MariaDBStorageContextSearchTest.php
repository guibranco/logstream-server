<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Database\Connection;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\MariaDBTestDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs against a real MariaDB. Skipped unless TEST_DB_DSN is set, e.g.
 *
 *   TEST_DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=logstream_test;charset=utf8mb4"
 *   TEST_DB_USER=root TEST_DB_PASS=secret vendor/bin/phpunit --testsuite integration
 *
 * WARNING: the database is wiped and rebuilt from migrations/ — use a throwaway one.
 */
final class MariaDBStorageContextSearchTest extends ContextSearchTestCase
{
    private ?MariaDBStorage $storage = null;

    protected function setUp(): void
    {
        if (MariaDBTestDatabase::pdo() === null) {
            self::markTestSkipped('TEST_DB_DSN not set; skipping MariaDB integration tests.');
        }
        MariaDBTestDatabase::pdo()->exec('DELETE FROM log_entries');

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return $this->storage ??= new MariaDBStorage(Connection::wrap(MariaDBTestDatabase::pdo()));
    }

    #[Test]
    public function migration_creates_the_context_fulltext_index(): void
    {
        $index = MariaDBTestDatabase::pdo()
            ->query("SHOW INDEX FROM log_entries WHERE Key_name = 'ft_context'")
            ->fetch();

        self::assertNotFalse($index);
        self::assertSame('FULLTEXT', $index['Index_type']);
    }

    #[Test]
    public function search_matches_whole_words_only(): void
    {
        // FULLTEXT treats "card_declined" as one word, so "card" only hits BB.
        self::assertSame(['BB'], $this->searchIds('context_search=card'));
    }
}
