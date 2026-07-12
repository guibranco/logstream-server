<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileRetentionEngine;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRetentionEngineTest extends TestCase
{
    use JsonlFixtureTrait;

    private string $tmpDir;
    private FileRetentionEngine $engine;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fre_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->engine = new FileRetentionEngine($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Safety
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_reports_a_missing_log_directory(): void
    {
        $engine = new FileRetentionEngine($this->tmpDir . '/does-not-exist');

        $result = $engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertSame(0, $result->pruned);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('not found', $result->summary);
    }

    #[Test]
    public function it_returns_zero_for_empty_policy(): void
    {
        $this->writeEntries('2020/01/01', [$this->makeEntry('2020-01-01T00:00:00.000Z')]);

        $policy = RetentionPolicy::fromArray(['name' => 'empty']);
        $result = $this->engine->purge($policy);

        self::assertSame(0, $result->pruned);
        self::assertFileExists($this->tmpDir . '/2020/01/01.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fast path
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_deletes_entire_old_file_on_date_only_policy(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T08:00:00.000Z'),
            $this->makeEntry('2020-01-01T20:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $result = $this->engine->purge($policy);

        self::assertSame(2, $result->pruned);
        self::assertSame(1, $result->filesRemoved);
        self::assertSame(0, $result->filesRewritten);
        self::assertFalse($result->dryRun);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
    }

    #[Test]
    public function it_skips_file_newer_than_cutoff(): void
    {
        $this->writeEntries('2099/12/31', [$this->makeEntry('2099-12-31T00:00:00.000Z')]);

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertSame(0, $result->pruned);
        self::assertFileExists($this->tmpDir . '/2099/12/31.jsonl');
    }

    #[Test]
    public function it_cleans_empty_directories_after_fast_path(): void
    {
        $this->writeEntries('2020/01/01', [$this->makeEntry('2020-01-01T00:00:00.000Z')]);

        $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertDirectoryDoesNotExist($this->tmpDir . '/2020/01');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/2020');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Slow path — partial rewrite
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_rewrites_file_keeping_non_matching_entries(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', level: 'debug'),
            $this->makeEntry('2020-01-01T01:00:00.000Z', level: 'info'),
            $this->makeEntry('2020-01-01T02:00:00.000Z', level: 'debug'),
        ]);

        $policy = RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]);
        $result = $this->engine->purge($policy);

        self::assertSame(2, $result->pruned);
        self::assertSame(0, $result->filesRemoved);
        self::assertSame(1, $result->filesRewritten);

        $remaining = $this->readEntries('2020/01/01');
        self::assertCount(1, $remaining);
        self::assertSame('info', $remaining[0]['level']);
    }

    #[Test]
    public function it_deletes_file_when_all_entries_pruned_via_field_filter(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', level: 'debug'),
            $this->makeEntry('2020-01-01T06:00:00.000Z', level: 'debug'),
        ]);

        $policy = RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]);
        $result = $this->engine->purge($policy);

        self::assertSame(2, $result->pruned);
        self::assertSame(1, $result->filesRemoved);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Dry run
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_reports_deletions_in_dry_run_without_deleting(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z'),
            $this->makeEntry('2020-01-01T06:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $result = $this->engine->purge($policy, dryRun: true);

        self::assertSame(2, $result->pruned);
        self::assertTrue($result->dryRun);
        self::assertStringContainsString('[dry-run]', $result->summary);
        self::assertFileExists($this->tmpDir . '/2020/01/01.jsonl');
    }

    #[Test]
    public function it_reports_a_would_be_rewrite_in_dry_run_with_a_field_filter(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', level: 'debug'),
            $this->makeEntry('2020-01-01T01:00:00.000Z', level: 'info'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']);
        $result = $this->engine->purge($policy, dryRun: true);

        self::assertSame(1, $result->pruned);
        self::assertSame(0, $result->filesRemoved);
        self::assertSame(1, $result->filesRewritten);
        self::assertCount(2, $this->readEntries('2020/01/01'));
    }

    #[Test]
    public function it_reports_a_would_be_removal_in_dry_run_when_all_entries_match(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', level: 'debug'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']);
        $result = $this->engine->purge($policy, dryRun: true);

        self::assertSame(1, $result->pruned);
        self::assertSame(1, $result->filesRemoved);
        self::assertSame(0, $result->filesRewritten);
        self::assertFileExists($this->tmpDir . '/2020/01/01.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Multiple files
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_prunes_across_multiple_day_files(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z'),
            $this->makeEntry('2020-01-01T01:00:00.000Z'),
        ]);
        $this->writeEntries('2020/01/02', [
            $this->makeEntry('2020-01-02T00:00:00.000Z'),
        ]);
        $this->writeEntries('2099/12/31', [
            $this->makeEntry('2099-12-31T00:00:00.000Z'),
        ]);

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]));

        self::assertSame(3, $result->pruned);
        self::assertSame(2, $result->filesRemoved);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/02.jsonl');
        self::assertFileExists($this->tmpDir . '/2099/12/31.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Result metadata
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_includes_policy_name_and_duration_in_result(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'my-policy', 'older_than_days' => 7]);
        $result = $this->engine->purge($policy);

        self::assertSame('my-policy', $result->policy);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
        self::assertNotEmpty($result->summary);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Robustness
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_treats_an_unopenable_file_as_untouched(): void
    {
        // A directory masquerading as "15.jsonl" matches the day-file pattern
        // but fopen() on it fails, exercising the "can't open" guard.
        mkdir($this->tmpDir . '/2020/01/15.jsonl', 0755, true);

        $result = $this->engine->purge(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        self::assertSame(0, $result->pruned);
        self::assertSame(0, $result->filesRemoved);
        self::assertSame(0, $result->filesRewritten);
    }

    #[Test]
    public function it_captures_a_per_file_error_and_continues(): void
    {
        $this->writeEntries('2020/01/01', [$this->makeEntry('2020-01-01T00:00:00.000Z')]);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING);

        try {
            $policy = RetentionPolicy::fromArray([
                'name'          => 'x',
                'message_regex' => '(unterminated',
            ]);
            $result = $this->engine->purge($policy);
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $result->pruned);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('Error processing', $result->warnings[0]);
    }
}
