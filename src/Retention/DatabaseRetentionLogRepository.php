<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Database\Connection;

/**
 * Writes retention run results to the retention_log table (migration 003)
 * and reads them back for GET /api/retention/log.
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

    public function recent(\DateTimeImmutable $since, ?string $policy = null, int $limit = 500): array
    {
        $sql = 'SELECT id, policy_name, deleted, dry_run, duration_ms, summary, warnings, error, ran_at
                FROM retention_log
                WHERE ran_at >= :since';
        $params = [':since' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];

        if ($policy !== null) {
            $sql .= ' AND policy_name = :policy';
            $params[':policy'] = $policy;
        }

        // $limit is an int, so inlining it is safe (LIMIT placeholders need
        // native prepares on MariaDB).
        $sql .= ' ORDER BY ran_at DESC, id DESC LIMIT ' . max(1, $limit);

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $entries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $entries[] = new RetentionLogEntry(
                id:         (int) $row['id'],
                policy:     (string) $row['policy_name'],
                deleted:    (int) $row['deleted'],
                dryRun:     (bool) (int) $row['dry_run'],
                durationMs: (int) $row['duration_ms'],
                summary:    $row['summary'] !== null ? (string) $row['summary'] : null,
                warnings:   RetentionLogEntry::decodeWarnings($row['warnings']),
                error:      $row['error'] !== null ? (string) $row['error'] : null,
                ranAt:      substr((string) $row['ran_at'], 0, 19),
            );
        }

        return $entries;
    }
}
