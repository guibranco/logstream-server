<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\DatabasePruner;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabasePrunerTest extends TestCase
{
    private \PDO $pdo;
    private DatabasePruner $pruner;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE log_entries (
                id         TEXT PRIMARY KEY,
                app_key    TEXT,
                app_id     TEXT,
                level      TEXT,
                category   TEXT,
                message    TEXT,
                timestamp  TEXT,
                created_at TEXT
            )',
        );

        $this->pruner = new DatabasePruner($this->pdo);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Safety
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_prunes_nothing_for_an_empty_policy(): void
    {
        $this->insert('1', timestamp: '2020-01-01 00:00:00');

        $count = $this->pruner->prune(RetentionPolicy::fromArray(['name' => 'empty']));

        self::assertSame(0, $count);
        self::assertSame(1, $this->countRows());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Live run
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_deletes_old_entries_by_date(): void
    {
        $this->insert('old', timestamp: '2020-01-01 00:00:00');
        $this->insert('new', timestamp: '2099-12-31 00:00:00');

        $count = $this->pruner->prune(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertSame(1, $count);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function it_deletes_entries_by_level(): void
    {
        $this->insert('d1', level: 'debug');
        $this->insert('d2', level: 'debug');
        $this->insert('i1', level: 'info');

        $count = $this->pruner->prune(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        self::assertSame(2, $count);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function it_applies_multiple_conditions_as_and(): void
    {
        $this->insert('match',    level: 'debug', timestamp: '2020-01-01 00:00:00');
        $this->insert('no-level', level: 'info',  timestamp: '2020-01-01 00:00:00');
        $this->insert('no-date',  level: 'debug', timestamp: '2099-12-31 00:00:00');

        $count = $this->pruner->prune(RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]));

        self::assertSame(1, $count);
        self::assertSame(2, $this->countRows());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function insert(
        string $id,
        string $appKey    = 'test-app',
        string $appId     = 'test',
        string $level     = 'info',
        string $category  = 'general',
        string $message   = 'Test message',
        string $timestamp = '2024-03-01 10:00:00',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO log_entries (id, app_key, app_id, level, category, message, timestamp, created_at)
             VALUES (:id, :app_key, :app_id, :level, :category, :message, :timestamp, :created_at)',
        );
        $stmt->execute([
            ':id'         => $id,
            ':app_key'    => $appKey,
            ':app_id'     => $appId,
            ':level'      => $level,
            ':category'   => $category,
            ':message'    => $message,
            ':timestamp'  => $timestamp,
            ':created_at' => $timestamp,
        ]);
    }

    private function countRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM log_entries')->fetchColumn();
    }
}
