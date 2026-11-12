<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Database\Connection;

/**
 * Writes retention run results to the retention_log table (migration 003).
 *
 * ran_at is written explicitly in UTC, matching how log_entries timestamps
 * are stored, instead of relying on the session time zone.
 */
final class DatabaseRetentionLogRepository implements RetentionLogRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(RetentionResult $result): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO retention_log
                (policy_name, deleted, dry_run, duration_ms, summary, warnings, error, ran_at)
             VALUES
                (:policy_name, :deleted, :dry_run, :duration_ms, :summary, :warnings, :error, :ran_at)',
        );

        $stmt->execute([
            ':policy_name' => $result->policy,
            ':deleted'     => max(0, $result->pruned),
            ':dry_run'     => $result->dryRun ? 1 : 0,
            ':duration_ms' => max(0, $result->durationMs),
            ':summary'     => $result->toArray()['summary'],
            ':warnings'    => $result->warnings === []
                ? null
                : json_encode(array_values($result->warnings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':error'       => $result->error,
            ':ran_at'      => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
