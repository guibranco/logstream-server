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

        foreach (JsonlDayFileScanner::listDayFiles($this->basePath) as [$fileDate, $filePath]) {
            $pruned += $this->pruneFile($fileDate, $filePath, $policy);
        }

        JsonlDayFileScanner::pruneEmptyDirectories($this->basePath);

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
                $count = JsonlDayFileScanner::countNonEmptyLines($filePath);
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

}
