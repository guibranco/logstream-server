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
        $path = $this->pathFor($entry->timestamp);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $line = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        $files = $this->resolveFiles($filters);

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
                if ($this->matchesFilters($entry, $filters)) {
                    $matched[] = $entry;
                }
            }
            fclose($handle);
        }

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
        // Scan all files for this ID or trace_id
        $result = $this->search(['_id_or_trace' => $id], 1, 0);
        if (!empty($result['entries'])) {
            return LogEntry::fromArray($result['entries'][0]);
        }
        return null;
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
    private function resolveFiles(array $filters): array
    {
        $from = isset($filters['date_from'])
            ? new \DateTimeImmutable($filters['date_from'])
            : null;

        $to = isset($filters['date_to'])
            ? new \DateTimeImmutable($filters['date_to'])
            : null;

        if ($from === null && $to === null) {
            return $this->allFiles();
        }

        $start = ($from ?? new \DateTimeImmutable('-90 days'))->setTime(0, 0);
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

    private function matchesFilters(array $entry, array $filters): bool
    {
        // Internal: find by ID or trace_id
        if (isset($filters['_id_or_trace'])) {
            return $entry['id'] === $filters['_id_or_trace']
                || $entry['trace_id'] === $filters['_id_or_trace'];
        }

        $exact = ['app_key', 'app_id', 'level', 'trace_id', 'batch_id'];
        foreach ($exact as $field) {
            if (!empty($filters[$field]) && ($entry[$field] ?? '') !== $filters[$field]) {
                return false;
            }
        }

        if (!empty($filters['user_agent'])) {
            if (!str_contains(strtolower((string)($entry['user_agent'] ?? '')), strtolower($filters['user_agent']))) {
                return false;
            }
        }

        if (!empty($filters['category'])) {
            if (!str_contains(strtolower((string)($entry['category'] ?? '')), strtolower($filters['category']))) {
                return false;
            }
        }

        if (!empty($filters['search'])) {
            if (!str_contains(strtolower((string)($entry['message'] ?? '')), strtolower($filters['search']))) {
                return false;
            }
        }

        if (!empty($filters['date_from'])) {
            if (($entry['timestamp'] ?? '') < $filters['date_from']) {
                return false;
            }
        }

        if (!empty($filters['date_to'])) {
            if (($entry['timestamp'] ?? '') > $filters['date_to']) {
                return false;
            }
        }

        return true;
    }
}
