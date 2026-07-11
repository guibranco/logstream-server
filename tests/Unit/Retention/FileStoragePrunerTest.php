<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileStoragePruner;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileStoragePrunerTest extends TestCase
{
    use JsonlFixtureTrait;

    private string $tmpDir;
    private FileStoragePruner $pruner;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/retention_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->pruner = new FileStoragePruner($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Safety
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_prunes_nothing_for_an_empty_policy(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'empty']);
        $count  = $this->pruner->prune($policy);

        self::assertSame(0, $count);
        self::assertFileExists($this->tmpDir . '/2020/01/01.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fast path — date-only policy
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_deletes_entire_old_file_when_policy_has_only_date_filter(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T08:00:00.000Z'),
            $this->makeEntry('2020-01-01T12:00:00.000Z'),
            $this->makeEntry('2020-01-01T20:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $count  = $this->pruner->prune($policy);

        self::assertSame(3, $count);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
    }

    #[Test]
    public function it_does_not_delete_file_newer_than_cutoff(): void
    {
        $this->writeEntries('2099/12/31', [
            $this->makeEntry('2099-12-31T00:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $count  = $this->pruner->prune($policy);

        self::assertSame(0, $count);
        self::assertFileExists($this->tmpDir . '/2099/12/31.jsonl');
    }

    #[Test]
    public function it_cleans_empty_directories_after_pruning(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $this->pruner->prune($policy);

        self::assertDirectoryDoesNotExist($this->tmpDir . '/2020/01');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/2020');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Slow path — partial file rewrite
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_rewrites_file_keeping_entries_that_do_not_match(): void
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
        $count = $this->pruner->prune($policy);

        self::assertSame(2, $count);

        $remaining = $this->readEntries('2020/01/01');
        self::assertCount(1, $remaining);
        self::assertSame('info', $remaining[0]['level']);
    }

    #[Test]
    public function it_deletes_file_when_all_entries_are_pruned_via_field_filter(): void
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
        $count = $this->pruner->prune($policy);

        self::assertSame(2, $count);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Field-only policy (no date filter)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_prunes_entries_by_app_key_without_date_filter(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', appKey: 'target-app'),
            $this->makeEntry('2020-01-01T01:00:00.000Z', appKey: 'other-app'),
        ]);

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'target-app']);
        $count  = $this->pruner->prune($policy);

        self::assertSame(1, $count);

        $remaining = $this->readEntries('2020/01/01');
        self::assertCount(1, $remaining);
        self::assertSame('other-app', $remaining[0]['app_key']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Pattern-based filters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_prunes_entries_matching_message_regex(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', message: 'user@hotmail.com signed up'),
            $this->makeEntry('2020-01-01T01:00:00.000Z', message: 'user@gmail.com signed up'),
        ]);

        $policy = RetentionPolicy::fromArray([
            'name'          => 'x',
            'message_regex' => '.*@hotmail\\.com.*',
        ]);
        $count = $this->pruner->prune($policy);

        self::assertSame(1, $count);

        $remaining = $this->readEntries('2020/01/01');
        self::assertCount(1, $remaining);
        self::assertStringContainsString('gmail.com', $remaining[0]['message']);
    }

    #[Test]
    public function it_prunes_entries_matching_message_glob(): void
    {
        $this->writeEntries('2020/01/01', [
            $this->makeEntry('2020-01-01T00:00:00.000Z', message: 'hello from user@hotmail.com'),
            $this->makeEntry('2020-01-01T01:00:00.000Z', message: 'hello from user@gmail.com'),
        ]);

        $policy = RetentionPolicy::fromArray([
            'name'         => 'x',
            'message_glob' => '*@hotmail.com*',
        ]);
        $count = $this->pruner->prune($policy);

        self::assertSame(1, $count);

        $remaining = $this->readEntries('2020/01/01');
        self::assertCount(1, $remaining);
        self::assertStringContainsString('gmail.com', $remaining[0]['message']);
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

        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $count  = $this->pruner->prune($policy);

        // 2 from 2020/01/01 + 1 from 2020/01/02 = 3; 2099 file untouched
        self::assertSame(3, $count);
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/01.jsonl');
        self::assertFileDoesNotExist($this->tmpDir . '/2020/01/02.jsonl');
        self::assertFileExists($this->tmpDir . '/2099/12/31.jsonl');
    }

}
