<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Database\Connection;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\StorageInterface;
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
    private static ?\PDO $pdo = null;
    private ?MariaDBStorage $storage = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!$dsn) {
            return;
        }

        self::$pdo = new \PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");

        // Fresh schema built by the real migrations, so 006 is exercised too.
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            self::$pdo->exec("DROP TABLE `{$table}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $migrations = glob(dirname(__DIR__, 3) . '/migrations/*.sql');
        sort($migrations);
        foreach ($migrations as $file) {
            self::$pdo->exec(file_get_contents($file));
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('TEST_DB_DSN not set; skipping MariaDB integration tests.');
        }
        self::$pdo->exec('DELETE FROM log_entries');

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return $this->storage ??= new MariaDBStorage(Connection::wrap(self::$pdo));
    }

    #[Test]
    public function migration_creates_the_context_fulltext_index(): void
    {
        $index = self::$pdo
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
