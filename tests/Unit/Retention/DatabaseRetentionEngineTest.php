<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseRetentionEngineTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseRetentionEngine $engine;

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

        $this->engine = new DatabaseRetentionEngine($this->pdo);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Safety
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_zero_for_empty_policy(): void
    {
        $this->insert('1', timestamp: '2020-01-01 00:00:00');

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'empty']));

        self::assertSame(0, $result->pruned);
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

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertSame(1, $result->pruned);
        self::assertFalse($result->dryRun);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function it_deletes_entries_by_level(): void
    {
        $this->insert('d1', level: 'debug');
        $this->insert('d2', level: 'debug');
        $this->insert('i1', level: 'info');

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        self::assertSame(2, $result->pruned);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function it_deletes_entries_by_app_key(): void
    {
        $this->insert('a1', appKey: 'target-app');
        $this->insert('a2', appKey: 'target-app');
        $this->insert('b1', appKey: 'other-app');

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'target-app']));

        self::assertSame(2, $result->pruned);
        self::assertSame(1, $this->countRows());
    }

    #[Test]
    public function it_applies_multiple_conditions_as_and(): void
    {
        $this->insert('match',    level: 'debug', timestamp: '2020-01-01 00:00:00');
        $this->insert('no-level', level: 'info',  timestamp: '2020-01-01 00:00:00');
        $this->insert('no-date',  level: 'debug', timestamp: '2099-12-31 00:00:00');

        $result = $this->engine->purge(RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]));

        self::assertSame(1, $result->pruned);
        self::assertSame(2, $this->countRows());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Dry run
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_counts_without_deleting_in_dry_run(): void
    {
        $this->insert('old', timestamp: '2020-01-01 00:00:00');
        $this->insert('new', timestamp: '2099-12-31 00:00:00');

        $result = $this->engine->purge(
            RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]),
            dryRun: true,
        );

        self::assertSame(1, $result->pruned);
        self::assertTrue($result->dryRun);
        self::assertStringContainsString('[dry-run]', $result->summary);
        self::assertSame(2, $this->countRows());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Result metadata
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_includes_policy_name_and_duration_in_result(): void
    {
        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'my-policy', 'level' => 'debug']));

        self::assertSame('my-policy', $result->policy);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
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
