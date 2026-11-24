<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Models\LogEntry;

/**
 * Flat-file storage using newline-delimited JSON (JSONL).
 *
 * Layout:  {basePath}/YYYY/MM/DD.jsonl
 *
 * Search strategy:
 *   1. Determine which daily files to scan from date_from/date_to (or scan all).
 *   2. Stream each file line-by-line, filter in memory.
 *
 * Suitable for low-to-moderate volumes. Switch to MariaDB for higher throughput.
 */
final class FileStorage implements StorageInterface
{
    public function __construct(private readonly string $basePath)
    {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function save(LogEntry $entry): void
    {
        $this->saveMany([$entry]);
    }

    /**
     * Append the entries to their day files, one write per file.
     *
     * @param list<LogEntry> $entries
     */
    public function saveMany(array $entries): void
    {
        $linesByPath = [];
        foreach ($entries as $entry) {
            $linesByPath[$this->pathFor($entry->timestamp)][] =
                json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        foreach ($linesByPath as $path => $lines) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_put_contents($path, implode('', $lines), FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException("Could not write to {$path}");
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function search(SearchFilters $filters, int $limit = 100, int $offset = 0): array
    {
        $matched = $this->scan($this->resolveFiles($filters), $filters->matches(...));

        // Sort newest-first
        usort($matched, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        $total   = count($matched);
        $entries = array_slice($matched, $offset, $limit);

        return [
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'entries' => $entries,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function findById(string $id): ?LogEntry
    {
        // Scan all files for this ID or trace_id; keep the newest match.
        $matched = $this->scan(
            $this->allFiles(),
            static fn(array $entry): bool => ($entry['id'] ?? null) === $id || ($entry['trace_id'] ?? null) === $id,
        );
        if ($matched === []) {
            return null;
        }

        usort($matched, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        return LogEntry::fromArray($matched[0]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function pathFor(\DateTimeImmutable $dt): string
    {
        return sprintf(
            '%s/%s/%s/%s.jsonl',
            rtrim($this->basePath, '/'),
            $dt->format('Y'),
            $dt->format('m'),
            $dt->format('d'),
        );
    }

    /**
     * Determine which daily files to scan.
     * Falls back to scanning everything if no date constraints are given.
     */
    private function resolveFiles(SearchFilters $filters): array
    {
        $from = $filters->dateFrom !== null
            ? new \DateTimeImmutable($filters->dateFrom, new \DateTimeZone('UTC'))
            : null;

        $to = $filters->dateTo !== null
            ? new \DateTimeImmutable($filters->dateTo, new \DateTimeZone('UTC'))
            : null;

        if ($from === null && $to === null) {
            return $this->allFiles();
        }

        // When no lower bound is given scan from the Unix epoch so that
        // historical entries are never excluded by an arbitrary cutoff.
        $start = ($from ?? new \DateTimeImmutable('@0'))->setTime(0, 0);
        $end   = ($to   ?? new \DateTimeImmutable())->setTime(23, 59, 59);

        $files   = [];
        $current = $start;

        while ($current <= $end) {
            $files[] = $this->pathFor($current);
            $current = $current->modify('+1 day');
        }

        return $files;
    }

    /** Recursively collect all *.jsonl files under the base path */
    private function allFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'jsonl') {
                $files[] = $file->getPathname();
            }
        }

        // Sort ascending so we end up newest-first after the usort in search()
        sort($files);

        return $files;
    }

    /**
     * Decoded entries of the given day files that satisfy $match.
     *
     * @param list<string>              $files
     * @param callable(array): bool     $match
     * @return list<array<string, mixed>>
     */
    private function scan(array $files, callable $match): array
    {
        $matched = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $entry = json_decode(trim($line), true);
                if (!$entry) {
                    continue;
                }
                if ($match($entry)) {
                    $matched[] = $entry;
                }
            }
            fclose($handle);
        }

        return $matched;
    }
}
