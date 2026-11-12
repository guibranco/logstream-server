<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Database\Connection;
use LogService\Retention\DatabaseRetentionVolumeAnalyzer;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeReport;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseRetentionVolumeAnalyzerTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseRetentionVolumeAnalyzer $analyzer;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE log_entries (
                id               TEXT PRIMARY KEY,
                app_key          TEXT,
                app_id           TEXT,
                level            TEXT,
                category         TEXT,
                message          TEXT,
                message_template TEXT,
                context          TEXT,
                timestamp        TEXT
            )',
        );

        $this->analyzer = new DatabaseRetentionVolumeAnalyzer(Connection::wrap($this->pdo));
    }

    #[Test]
    public function it_counts_entries_and_payload_bytes_per_day(): void
    {
        $this->insert(0, message: 'abcd');
        $this->insert(0, message: 'ab', context: '{}');
        $this->insert(4, message: 'x');

        $report = $this->analyzer->analyze([], 7);

        self::assertSame(RetentionVolumeReport::SIZE_BASIS_PAYLOAD, $report->sizeBasis);
        self::assertSame(2, $report->buckets[6]['count']);
        self::assertSame(8, $report->buckets[6]['bytes']);
        self::assertSame(1, $report->buckets[2]['count']);
        self::assertSame(3, $report->totals['count']);
        self::assertSame(0, $report->totals['due_count']);
    }

    #[Test]
    public function it_reports_matched_and_due_per_policy(): void
    {
        $this->insert(1, level: 'debug');
        $this->insert(1);
        $this->insert(5, level: 'debug');
        $this->insert(5);

        $report = $this->analyzer->analyze(
            [RetentionPolicy::fromArray(['name' => 'debug-2d', 'level' => 'debug', 'older_than_days' => 2])],
            7,
        );

        $stats = $report->policies[0];
        self::assertSame(2, $stats['matched_count']);
        self::assertSame(1, $stats['due_count']);
        self::assertSame(0.5, $stats['matched_share']);
        self::assertSame(1, $report->buckets[1]['due_count']);
        self::assertSame(0, $report->buckets[5]['due_count']);
    }

    #[Test]
    public function it_counts_an_entry_due_under_two_policies_once(): void
    {
        $this->insert(10, level: 'debug', message: 'cache miss');

        $report = $this->analyzer->analyze([
            RetentionPolicy::fromArray(['name' => 'all-7d', 'older_than_days' => 7]),
            RetentionPolicy::fromArray(['name' => 'cache-2d', 'message_glob' => 'cache*', 'older_than_days' => 2]),
        ], 30);

        self::assertSame(1, $report->totals['due_count']);
        self::assertSame(1, $report->policies[0]['due_count']);
        self::assertSame(1, $report->policies[1]['due_count']);
    }

    #[Test]
    public function it_ignores_empty_policies(): void
    {
        $this->insert(10);

        $report = $this->analyzer->analyze([RetentionPolicy::fromArray(['name' => 'empty'])], 30);

        self::assertFalse($report->policies[0]['active']);
        self::assertSame(0, $report->policies[0]['matched_count']);
        self::assertSame(0, $report->totals['due_count']);
    }

    #[Test]
    public function it_folds_entries_beyond_the_window_into_older(): void
    {
        $this->insert(40);

        $report = $this->analyzer->analyze([], 30);

        self::assertSame(1, $report->older['count']);
        self::assertSame(1, $report->totals['count']);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function insert(int $age, string $level = 'info', string $message = 'msg', ?string $context = null): void
    {
        $ts = (new \DateTimeImmutable('today'))->modify("-{$age} days")->setTime(12, 0)->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO log_entries (id, app_key, app_id, level, category, message, context, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([(string) ++$this->seq, 'app', 'prod', $level, 'general', $message, $context, $ts]);
    }
}
