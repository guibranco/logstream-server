<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * File-mode counterpart of the retention_log table: one JSON line per run,
 * appended to a single file (by default `retention_log.jsonl` next to the
 * log directory, so the day-file scanners never see it).
 *
 * Lines older than $keepDays are dropped whenever a run is recorded, so the
 * file stays small without a separate clean-up job.
 */
final class FileRetentionLogRepository implements RetentionLogRepositoryInterface
{
    public function __construct(
        private readonly string $path,
        private readonly int    $keepDays = 365,
    ) {}

    public function record(RetentionResult $result): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory {$dir}");
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$this->path}");
        }

        try {
            flock($handle, LOCK_EX);

            $cutoff = gmdate('Y-m-d H:i:s', time() - $this->keepDays * 86400);
            $lines  = [];
            $nextId = 1;

            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $nextId = max($nextId, (int) ($row['id'] ?? 0) + 1);
                if ((string) ($row['ran_at'] ?? '') >= $cutoff) {
                    $lines[] = rtrim($line, "\r\n");
                }
            }

            $lines[] = json_encode([
                'id'          => $nextId,
                'policy_name' => $result->policy,
                'deleted'     => max(0, $result->pruned),
                'dry_run'     => $result->dryRun,
                'duration_ms' => max(0, $result->durationMs),
                'summary'     => $result->toArray()['summary'],
                'warnings'    => array_values($result->warnings),
                'error'       => $result->error,
                'ran_at'      => gmdate('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode("\n", $lines) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function recent(\DateTimeImmutable $since, ?string $policy = null, int $limit = 500): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$this->path}");
        }

        $from    = $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $entries = [];

        try {
            flock($handle, LOCK_SH);
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (!is_array($row) || (string) ($row['ran_at'] ?? '') < $from) {
                    continue;
                }
                if ($policy !== null && ($row['policy_name'] ?? null) !== $policy) {
                    continue;
                }
                $entries[] = new RetentionLogEntry(
                    id:         (int) ($row['id'] ?? 0),
                    policy:     (string) ($row['policy_name'] ?? ''),
                    deleted:    (int) ($row['deleted'] ?? 0),
                    dryRun:     (bool) ($row['dry_run'] ?? false),
                    durationMs: (int) ($row['duration_ms'] ?? 0),
                    summary:    isset($row['summary']) ? (string) $row['summary'] : null,
                    warnings:   RetentionLogEntry::decodeWarnings($row['warnings'] ?? null),
                    error:      isset($row['error']) ? (string) $row['error'] : null,
                    ranAt:      (string) $row['ran_at'],
                );
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        usort($entries, static fn(RetentionLogEntry $a, RetentionLogEntry $b): int =>
            [$b->ranAt, $b->id] <=> [$a->ranAt, $a->id]);

        return array_slice($entries, 0, max(1, $limit));
    }
}
