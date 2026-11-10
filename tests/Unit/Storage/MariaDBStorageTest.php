<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Database\Connection;
use LogService\Models\LogEntry;
use LogService\Storage\MariaDBStorage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The SQL that MariaDBStorage builds for exact and list filters, run against
 * an in-memory SQLite table with the same columns. The MariaDB-specific parts
 * (JSON functions, FULLTEXT) are covered by tests/Integration against MariaDB.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class MariaDBStorageTest extends TestCase
{
    private MariaDBStorage $storage;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("
            CREATE TABLE log_entries (
                id         TEXT PRIMARY KEY,
                trace_id   TEXT NOT NULL,
                batch_id   TEXT NULL,
                app_key    TEXT NOT NULL,
                app_id     TEXT NOT NULL,
                user_agent TEXT NULL,
                sender_ip  TEXT NULL,
                level      TEXT NOT NULL,
                category   TEXT NOT NULL,
                message    TEXT NOT NULL,
                message_template TEXT NULL,
                context    TEXT NULL,
                timestamp  TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->storage = new MariaDBStorage(Connection::wrap($pdo));

        $this->save('01A', 'billing-api', 'web', 'error');
        $this->save('01B', 'billing-api', 'worker', 'info');
        $this->save('01C', 'orders-api', 'web', 'critical');
        $this->save('01D', 'orders-api', 'web', 'warning');
        $this->save('01E', 'users-api', 'web', 'error');
    }

    #[Test]
    public function it_filters_by_a_single_level(): void
    {
        self::assertSame(['01A', '01E'], $this->ids(['level' => 'error']));
    }

    #[Test]
    public function it_matches_any_level_of_a_list(): void
    {
        self::assertSame(['01A', '01C', '01E'], $this->ids(['level' => ['error', 'critical']]));
    }

    #[Test]
    public function it_combines_lists_and_single_values(): void
    {
        $filters = ['app_key' => ['billing-api', 'orders-api'], 'level' => ['error', 'critical'], 'app_id' => 'web'];

        self::assertSame(['01A', '01C'], $this->ids($filters));
    }

    #[Test]
    public function it_counts_matches_of_a_list_across_pages(): void
    {
        $result = $this->storage->search(['app_key' => ['billing-api', 'users-api']], 1, 1);

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['entries']);
    }

    #[Test]
    public function it_ignores_empty_filters(): void
    {
        self::assertCount(5, $this->ids(['level' => '', 'app_key' => []]));
    }

    /** Sorted ids of the entries the filters return. */
    private function ids(array $filters): array
    {
        $ids = array_column($this->storage->search($filters)['entries'], 'id');
        sort($ids);

        return $ids;
    }

    private function save(string $id, string $appKey, string $appId, string $level): void
    {
        $this->storage->save(LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'batch_id'   => null,
            'app_key'    => $appKey,
            'app_id'     => $appId,
            'level'      => $level,
            'category'   => 'test',
            'message'    => "Entry {$id}",
            'user_agent' => null,
            'context'    => null,
            'timestamp'  => '2026-03-01T10:00:00.000Z',
            'created_at' => '2026-03-01T10:00:00.000Z',
        ]));
    }
}
