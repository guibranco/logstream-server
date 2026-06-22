<?php

declare(strict_types=1);

namespace LogService\Retention;

final class FileRetentionEngine implements RetentionEngineInterface
{
    public function __construct(private readonly string $basePath) {}

    public function purge(RetentionPolicy $policy, bool $dryRun = false): RetentionResult
    {
        $start = hrtime(true);

        if ($policy->isEmpty()) {
            return new RetentionResult(
                policy:     $policy->name,
                pruned:     0,
                dryRun:     $dryRun,
                durationMs: 0,
                summary:    'Policy is empty — nothing to do',
            );
        }

        $deleted        = 0;
        $filesRemoved   = 0;
        $filesRewritten = 0;
        $warnings       = [];

        foreach ($this->listDayFiles() as [$fileDate, $filePath]) {
            try {
                [$d, $fr, $frw] = $this->processFile($fileDate, $filePath, $policy, $dryRun);
                $deleted        += $d;
                $filesRemoved   += $fr;
                $filesRewritten += $frw;
            } catch (\Throwable $e) {
                $warnings[] = "Error processing {$filePath}: " . $e->getMessage();
            }
        }

        if (!$dryRun) {
            $this->pruneEmptyDirectories($this->basePath);
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
        $prefix     = $dryRun ? '[dry-run] ' : '';
        $summary    = "{$prefix}{$deleted} entries pruned, {$filesRemoved} files removed, {$filesRewritten} files rewritten";

        return new RetentionResult(
            policy:         $policy->name,
            pruned:         $deleted,
            filesRemoved:   $filesRemoved,
            filesRewritten: $filesRewritten,
            dryRun:         $dryRun,
            durationMs:     $durationMs,
            summary:        $summary,
            warnings:       $warnings,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array{int, int, int}  [deleted, filesRemoved, filesRewritten]
     */
    private function processFile(
        \DateTimeImmutable $fileDate,
        string             $filePath,
        RetentionPolicy    $policy,
        bool               $dryRun,
    ): array {
        $cutoff = $policy->getCutoffDate();

        if ($cutoff !== null) {
            $fileMidnight    = $fileDate->setTime(0, 0, 0);
            $nextDayMidnight = $fileMidnight->modify('+1 day');

            if ($fileMidnight >= $cutoff) {
                return [0, 0, 0];
            }

            if ($nextDayMidnight <= $cutoff && !$policy->hasFieldFilters()) {
                $count = $this->countNonEmptyLines($filePath);
                if (!$dryRun) {
                    @unlink($filePath);
                }
                return [$count, 1, 0];
            }
        }

        return $this->rewriteFile($filePath, $policy, $dryRun);
    }

    /**
     * @return array{int, int, int}  [deleted, filesRemoved, filesRewritten]
     */
    private function rewriteFile(string $filePath, RetentionPolicy $policy, bool $dryRun): array
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return [0, 0, 0];
        }

        $kept    = [];
        $deleted = 0;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $entry = json_decode($trimmed, true);
            if (!is_array($entry)) {
                $kept[] = $trimmed;
                continue;
            }

            if (EntryMatcher::shouldDelete($policy, $entry)) {
                $deleted++;
            } else {
                $kept[] = $trimmed;
            }
        }
        fclose($handle);

        if ($deleted === 0) {
            return [0, 0, 0];
        }

        if (!$dryRun) {
            if (empty($kept)) {
                @unlink($filePath);
                return [$deleted, 1, 0];
            }

            $tmp = $filePath . '.tmp';
            file_put_contents($tmp, implode("\n", $kept) . "\n", LOCK_EX);
            rename($tmp, $filePath);
            return [$deleted, 0, 1];
        }

        // dry-run: report what would happen
        return [$deleted, empty($kept) ? 1 : 0, empty($kept) ? 0 : 1];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
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

    private function pruneEmptyDirectories(string $dir): void
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
                $this->pruneEmptyDirectories($path);
                if ($this->isDirEmpty($path)) {
                    @rmdir($path);
                }
            }
        }
    }

    private function isDirEmpty(string $dir): bool
    {
        $entries = scandir($dir);
        return $entries !== false && count($entries) === 2;
    }
}
