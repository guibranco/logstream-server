<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Pruner for the flat-file (JSONL) storage backend.
 *
 * Directory layout expected:  {basePath}/YYYY/MM/DD.jsonl
 *
 * Two code paths are used:
 *
 *   Fast path — policy has a date filter but no field filters:
 *     Entire day-files whose dates fall strictly before the cutoff are deleted
 *     wholesale without reading their contents.
 *
 *   Slow path — all other combinations:
 *     Each entry is decoded and tested individually. Matching entries are
 *     dropped; the file is rewritten with only the survivors (or deleted when
 *     all entries were pruned). Empty parent directories are removed afterwards.
 */
final class FileStoragePruner implements PrunerInterface
{
    public function __construct(private readonly string $basePath) {}

    // ──────────────────────────────────────────────────────────────────────────

    public function prune(RetentionPolicy $policy): int
    {
        if ($policy->isEmpty()) {
            return 0;
        }

        $pruned = 0;

        foreach ($this->listDayFiles() as [$fileDate, $filePath]) {
            $pruned += $this->pruneFile($fileDate, $filePath, $policy);
        }

        $this->cleanEmptyDirs($this->basePath);

        return $pruned;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function pruneFile(
        \DateTimeImmutable $fileDate,
        string             $filePath,
        RetentionPolicy    $policy,
    ): int {
        $cutoff = $policy->getCutoffDate();

        if ($cutoff !== null) {
            $fileMidnight    = $fileDate->setTime(0, 0, 0);
            $nextDayMidnight = $fileMidnight->modify('+1 day');

            // File is on or after the cutoff — nothing in it can be old enough
            if ($fileMidnight >= $cutoff) {
                return 0;
            }

            // Every entry in this file predates the cutoff and there are no
            // field filters to check: delete the whole file in one go.
            if ($nextDayMidnight <= $cutoff && !$policy->hasFieldFilters()) {
                $count = $this->countNonEmptyLines($filePath);
                @unlink($filePath);
                return $count;
            }
        }

        // Slow path: examine each entry individually
        return $this->pruneEntriesFromFile($filePath, $policy);
    }

    private function pruneEntriesFromFile(string $filePath, RetentionPolicy $policy): int
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return 0;
        }

        $kept   = [];
        $pruned = 0;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $entry = json_decode($trimmed, true);
            if (!is_array($entry)) {
                // Preserve corrupt / non-JSON lines unchanged
                $kept[] = $trimmed;
                continue;
            }

            if ($policy->matchesEntry($entry)) {
                $pruned++;
            } else {
                $kept[] = $trimmed;
            }
        }
        fclose($handle);

        if ($pruned === 0) {
            return 0;
        }

        if (empty($kept)) {
            @unlink($filePath);
        } else {
            file_put_contents($filePath, implode("\n", $kept) . "\n", LOCK_EX);
        }

        return $pruned;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Yields [\DateTimeImmutable $date, string $path] for every *.jsonl file.
     *
     * @return array<int, array{\DateTimeImmutable, string}>
     */
    private function listDayFiles(): array
    {
        $files = [];

        if (!is_dir($this->basePath)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
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

    private function countNonEmptyLines(string $filePath): int
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

    private function cleanEmptyDirs(string $dir): void
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
                $this->cleanEmptyDirs($path);
                if ($this->isDirEmpty($path)) {
                    @rmdir($path);
                }
            }
        }
    }

    private function isDirEmpty(string $dir): bool
    {
        $entries = scandir($dir);
        return $entries !== false && count($entries) === 2; // only '.' and '..'
    }
}
