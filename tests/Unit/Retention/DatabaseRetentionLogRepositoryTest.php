<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Database\Connection;
use LogService\Retention\DatabaseRetentionLogRepository;
use LogService\Retention\RetentionResult;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseRetentionLogRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseRetentionLogRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE retention_log (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                policy_name  VARCHAR(255) NOT NULL,
                deleted      INTEGER      NOT NULL DEFAULT 0,
                dry_run      INTEGER      NOT NULL DEFAULT 0,
                duration_ms  INTEGER      NOT NULL DEFAULT 0,
                summary      TEXT         NULL,
                warnings     TEXT         NULL,
                error        TEXT         NULL,
                ran_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        $this->repo = new DatabaseRetentionLogRepository(Connection::wrap($this->pdo));
    }

    #[Test]
    public function it_records_a_successful_run(): void
    {
        $this->repo->record(new RetentionResult(
            policy:     'debug-7d',
            pruned:     42,
            dryRun:     false,
            durationMs: 15,
            summary:    '42 entries pruned',
        ));

        $row = $this->pdo->query('SELECT * FROM retention_log')->fetch();

        self::assertSame('debug-7d', $row['policy_name']);
        self::assertSame(42, (int) $row['deleted']);
        self::assertSame(0, (int) $row['dry_run']);
        self::assertSame(15, (int) $row['duration_ms']);
        self::assertSame('42 entries pruned', $row['summary']);
        self::assertNull($row['warnings']);
        self::assertNull($row['error']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['ran_at']);
    }

    #[Test]
    public function it_records_a_dry_run_with_warnings_and_error(): void
    {
        $this->repo->record(new RetentionResult(
            policy:   'broken',
            pruned:   0,
            error:    'Unknown column message_template',
            dryRun:   true,
            summary:  'Error: Unknown column message_template',
            warnings: ['first', 'second'],
        ));

        $row = $this->pdo->query('SELECT * FROM retention_log')->fetch();

        self::assertSame(1, (int) $row['dry_run']);
        self::assertSame('Unknown column message_template', $row['error']);
        self::assertSame(['first', 'second'], json_decode($row['warnings'], true));
    }

    #[Test]
    public function it_falls_back_to_the_default_summary(): void
    {
        $this->repo->record(new RetentionResult(policy: 'p', pruned: 3));

        $row = $this->pdo->query('SELECT summary FROM retention_log')->fetch();

        self::assertSame('3 entries pruned', $row['summary']);
    }
}
