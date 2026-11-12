<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Retention;

use LogService\Database\Connection;
use LogService\Retention\DatabaseRetentionVolumeAnalyzer;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the volume query against a real MariaDB, so REGEXP, LIKE, JSON context
 * lengths and the nested GROUP BY are checked in the production dialect.
 * Skipped unless TEST_DB_DSN is set (see MariaDBStorageContextSearchTest).
 *
 * WARNING: the database is wiped and rebuilt from migrations/ — use a throwaway one.
 */
final class MariaDBRetentionVolumeAnalyzerTest extends TestCase
{
    private static ?\PDO $pdo = null;
    private int $seq = 0;

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
    }

    #[Test]
    public function it_buckets_entries_and_measures_policies(): void
    {
        $this->insert(0, 'info', 'Order 1 placed', '{"order":1}');
        $this->insert(3, 'debug', 'Cache miss for user', null);
        $this->insert(3, 'info', 'Webhook received', null, 'Webhook {event}');
        $this->insert(10, 'debug', 'Cache hit', null);
        $this->insert(40, 'info', 'Old entry', null);

        $report = (new DatabaseRetentionVolumeAnalyzer(Connection::wrap(self::$pdo)))->analyze([
            RetentionPolicy::fromArray(['name' => 'debug-2d', 'level' => 'debug', 'older_than_days' => 2]),
            RetentionPolicy::fromArray(['name' => 'cache-regex', 'message_regex' => '^Cache', 'older_than_days' => 7]),
            RetentionPolicy::fromArray(['name' => 'webhook-glob', 'message_glob' => 'Webhook {*}', 'older_than_days' => 30]),
            RetentionPolicy::fromArray(['name' => 'empty']),
        ], 30);

        self::assertSame(5, $report->totals['count']);
        self::assertSame(1, $report->older['count']);
        self::assertSame(1, $report->buckets[29]['count']);
        self::assertSame(2, $report->buckets[26]['count']);
        self::assertSame(
            strlen('Order 1 placed') + strlen('{"order":1}'),
            $report->buckets[29]['bytes'],
        );

        [$debug, $cache, $webhook, $empty] = $report->policies;
        self::assertSame([2, 2], [$debug['matched_count'], $debug['due_count']]);
        self::assertSame([2, 1], [$cache['matched_count'], $cache['due_count']]);
        self::assertSame([1, 0], [$webhook['matched_count'], $webhook['due_count']]);
        self::assertSame(0, $empty['matched_count']);

        // The entry at age 10 is due under two policies but counted once.
        self::assertSame(2, $report->totals['due_count']);
    }

    private function insert(int $age, string $level, string $message, ?string $context, ?string $template = null): void
    {
        $ts = (new \DateTimeImmutable('today'))->modify("-{$age} days")->setTime(12, 0)->format('Y-m-d H:i:s');

        self::$pdo->prepare(
            'INSERT INTO log_entries (id, trace_id, app_key, app_id, level, category, message, message_template, context, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            sprintf('01HZB%021d', ++$this->seq), 'trace', 'app', 'prod', $level, 'general',
            $message, $template, $context, $ts,
        ]);
    }
}
