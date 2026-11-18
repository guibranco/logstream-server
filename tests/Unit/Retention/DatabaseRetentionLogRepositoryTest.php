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

    #[Test]
    public function recent_returns_runs_since_the_cutoff_newest_first(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO retention_log (policy_name, deleted, dry_run, duration_ms, summary, warnings, error, ran_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute(['old', 1, 0, 1, 's', null, null, '2026-01-01 00:00:00']);
        $insert->execute(['a', 5, 0, 10, '5 entries pruned', null, null, '2026-11-10 08:00:00']);
        $insert->execute(['b', 0, 1, 2, 'Error: boom', '["w1"]', 'boom', '2026-11-12 09:30:00']);

        $entries = $this->repo->recent(new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone('UTC')));

        self::assertCount(2, $entries);
        self::assertSame('b', $entries[0]->policy);
        self::assertTrue($entries[0]->dryRun);
        self::assertSame(['w1'], $entries[0]->warnings);
        self::assertSame('boom', $entries[0]->error);
        self::assertSame('2026-11-12T09:30:00+00:00', $entries[0]->toArray()['ran_at']);
        self::assertSame('a', $entries[1]->policy);
        self::assertSame(5, $entries[1]->deleted);
        self::assertSame([], $entries[1]->warnings);
    }

    #[Test]
    public function recent_filters_by_policy_and_limits_the_rows(): void
    {
        foreach (['a', 'b', 'a', 'a'] as $policy) {
            $this->repo->record(new RetentionResult(policy: $policy, pruned: 1));
        }

        $since   = new \DateTimeImmutable('-1 day');
        $entries = $this->repo->recent($since, 'a', 2);

        self::assertCount(2, $entries);
        self::assertSame(['a', 'a'], array_map(fn($e) => $e->policy, $entries));
        self::assertGreaterThan($entries[1]->id, $entries[0]->id);
    }
}
