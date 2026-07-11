<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Filesystem helpers shared by FileStoragePruner and FileRetentionEngine for
 * walking a {basePath}/YYYY/MM/DD.jsonl log directory.
 */
final class JsonlDayFileScanner
{
    /**
     * Yields [\DateTimeImmutable $date, string $path] for every *.jsonl file.
     *
     * @return array<int, array{\DateTimeImmutable, string}>
     */
    public static function listDayFiles(string $basePath): array
    {
        $files = [];

        if (!is_dir($basePath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'jsonl') {
                continue;
            }

            // Expect path ending in YYYY/MM/DD.jsonl (forward or back slash)
            if (!preg_match('#(\d{4})[/\\\\](\d{2})[/\\\\](\d{2})\.jsonl$#', $file->getPathname(), $m)) {
                continue;
            }

            $files[] = [
                new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]}"),
                $file->getPathname(),
            ];
        }

        return $files;
    }

    public static function countNonEmptyLines(string $filePath): int
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return 0;
        }
        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);
        return $count;
    }

    public static function pruneEmptyDirectories(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::pruneEmptyDirectories($path);
                if (self::isDirEmpty($path)) {
                    @rmdir($path);
                }
            }
        }
    }

    private static function isDirEmpty(string $dir): bool
    {
        $entries = scandir($dir);
        return $entries !== false && count($entries) === 2; // only '.' and '..'
    }
}
