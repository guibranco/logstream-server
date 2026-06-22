<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileRetentionEngine;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRetentionEngineTest extends TestCase
{
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
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<int,array> $entries */
    private function writeEntries(string $relativePath, array $entries): void
    {
        $path = $this->tmpDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) . '.jsonl';
        @mkdir(dirname($path), 0755, true);

        $lines = array_map(
            fn(array $e) => json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $entries,
        );
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** @return array<int,array> */
    private function readEntries(string $relativePath): array
    {
        $path = $this->tmpDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) . '.jsonl';
        if (!file_exists($path)) {
            return [];
        }
        $lines   = explode("\n", trim((string) file_get_contents($path)));
        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    private function makeEntry(
        string $timestamp = '2024-03-01T10:00:00.000Z',
        string $appKey    = 'test-app',
        string $appId     = 'test',
        string $level     = 'info',
        string $category  = 'general',
        string $message   = 'Test message',
    ): array {
        return [
            'id'         => uniqid('', true),
            'trace_id'   => 'trace-default',
            'batch_id'   => null,
            'app_key'    => $appKey,
            'app_id'     => $appId,
            'user_agent' => 'TestClient/1.0',
            'level'      => $level,
            'category'   => $category,
            'message'    => $message,
            'context'    => null,
            'timestamp'  => $timestamp,
            'created_at' => $timestamp,
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
