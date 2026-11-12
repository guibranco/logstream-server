<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileRetentionVolumeAnalyzer;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRetentionVolumeAnalyzerTest extends TestCase
{
    use JsonlFixtureTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/logstream_volume_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    #[Test]
    public function it_counts_entries_and_line_bytes_per_day(): void
    {
        $this->writeDay(0, [$this->entryAt(0), $this->entryAt(0)]);
        $this->writeDay(3, [$this->entryAt(3)]);

        $report = (new FileRetentionVolumeAnalyzer($this->tmpDir))->analyze([], 7);

        self::assertSame(RetentionVolumeReport::SIZE_BASIS_FILE, $report->sizeBasis);
        self::assertSame(2, $this->bucket($report, 0)['count']);
        self::assertSame(1, $this->bucket($report, 3)['count']);
        self::assertSame(3, $report->totals['count']);
        self::assertSame($this->fileBytes(), $report->totals['bytes']);
    }

    #[Test]
    public function it_reports_matched_and_due_per_policy(): void
    {
        $this->writeDay(1, [$this->entryAt(1, level: 'debug'), $this->entryAt(1)]);
        $this->writeDay(5, [$this->entryAt(5, level: 'debug'), $this->entryAt(5)]);

        $policy = RetentionPolicy::fromArray(['name' => 'debug-2d', 'level' => 'debug', 'older_than_days' => 2]);
        $report = (new FileRetentionVolumeAnalyzer($this->tmpDir))->analyze([$policy], 7);

        $stats = $report->policies[0];
        self::assertSame(2, $stats['matched_count']);
        self::assertSame(1, $stats['due_count']);
        self::assertSame(0.5, $stats['matched_share']);
        self::assertSame(0, $this->bucket($report, 1)['due_count']);
        self::assertSame(1, $this->bucket($report, 5)['due_count']);
    }

    #[Test]
    public function it_counts_an_entry_due_under_two_policies_once(): void
    {
        $this->writeDay(10, [$this->entryAt(10, level: 'debug')]);

        $report = (new FileRetentionVolumeAnalyzer($this->tmpDir))->analyze([
            RetentionPolicy::fromArray(['name' => 'all-7d', 'older_than_days' => 7]),
            RetentionPolicy::fromArray(['name' => 'debug-2d', 'level' => 'debug', 'older_than_days' => 2]),
        ], 30);

        self::assertSame(1, $report->totals['due_count']);
        self::assertSame(1, $report->policies[0]['due_count']);
        self::assertSame(1, $report->policies[1]['due_count']);
    }

    #[Test]
    public function it_ignores_empty_policies(): void
    {
        $this->writeDay(10, [$this->entryAt(10)]);

        $report = (new FileRetentionVolumeAnalyzer($this->tmpDir))->analyze(
            [RetentionPolicy::fromArray(['name' => 'empty'])],
            30,
        );

        self::assertSame(0, $report->policies[0]['matched_count']);
        self::assertSame(0, $report->totals['due_count']);
    }

    #[Test]
    public function it_returns_an_empty_report_when_the_directory_is_missing(): void
    {
        $report = (new FileRetentionVolumeAnalyzer($this->tmpDir . '/missing'))->analyze([], 3);

        self::assertCount(3, $report->buckets);
        self::assertSame(0, $report->totals['count']);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function writeDay(int $age, array $entries): void
    {
        $this->writeEntries((new \DateTimeImmutable('today'))->modify("-{$age} days")->format('Y/m/d'), $entries);
    }

    private function entryAt(int $age, string $level = 'info'): array
    {
        $ts = (new \DateTimeImmutable('today'))->modify("-{$age} days")->setTime(12, 0)->format(\DateTimeInterface::RFC3339);
        return $this->makeEntry(timestamp: $ts, level: $level);
    }

    private function bucket(RetentionVolumeReport $report, int $age): array
    {
        foreach ($report->buckets as $bucket) {
            if ($bucket['age_days'] === $age) {
                return $bucket;
            }
        }
        self::fail("No bucket for age {$age}");
    }

    private function fileBytes(): int
    {
        $total = 0;
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $total += $file->getSize();
        }
        return $total;
    }
}
