<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\JsonlDayFileScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonlDayFileScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/jsonl_scanner_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // listDayFiles()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_no_files_when_base_path_does_not_exist(): void
    {
        self::assertSame([], JsonlDayFileScanner::listDayFiles($this->tmpDir));
    }

    #[Test]
    public function it_finds_day_files_matching_the_expected_layout(): void
    {
        $this->touch('2020/01/15.jsonl');
        $this->touch('2020/02/03.jsonl');

        $files = JsonlDayFileScanner::listDayFiles($this->tmpDir);

        self::assertCount(2, $files);
        $dates = array_map(fn(array $f) => $f[0]->format('Y-m-d'), $files);
        self::assertContains('2020-01-15', $dates);
        self::assertContains('2020-02-03', $dates);
    }

    #[Test]
    public function it_ignores_files_with_a_different_extension(): void
    {
        $this->touch('2020/01/15.jsonl');
        $this->touch('2020/01/notes.txt');

        $files = JsonlDayFileScanner::listDayFiles($this->tmpDir);

        self::assertCount(1, $files);
    }

    #[Test]
    public function it_ignores_jsonl_files_that_do_not_match_the_day_pattern(): void
    {
        $this->touch('2020/01/15.jsonl');
        $this->touch('summary.jsonl');

        $files = JsonlDayFileScanner::listDayFiles($this->tmpDir);

        self::assertCount(1, $files);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // countNonEmptyLines()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_zero_when_the_file_does_not_exist(): void
    {
        self::assertSame(0, JsonlDayFileScanner::countNonEmptyLines($this->tmpDir . '/missing.jsonl'));
    }

    #[Test]
    public function it_counts_only_non_blank_lines(): void
    {
        $path = $this->touch('2020/01/15.jsonl', "{\"a\":1}\n\n{\"a\":2}\n   \n");

        self::assertSame(2, JsonlDayFileScanner::countNonEmptyLines($path));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // pruneEmptyDirectories()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_does_nothing_when_the_directory_does_not_exist(): void
    {
        JsonlDayFileScanner::pruneEmptyDirectories($this->tmpDir);

        self::assertDirectoryDoesNotExist($this->tmpDir);
    }

    #[Test]
    public function it_removes_nested_empty_directories_but_keeps_non_empty_ones(): void
    {
        $this->touch('2020/01/15.jsonl');
        mkdir($this->tmpDir . '/2020/02', 0755, true);

        JsonlDayFileScanner::pruneEmptyDirectories($this->tmpDir);

        self::assertDirectoryExists($this->tmpDir . '/2020/01');
        self::assertFileExists($this->tmpDir . '/2020/01/15.jsonl');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/2020/02');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function touch(string $relativePath, string $contents = ''): string
    {
        $path = $this->tmpDir . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $contents);
        return $path;
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
