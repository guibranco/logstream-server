<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Database\Connection;

final class DatabaseRetentionEngine implements RetentionEngineInterface
{
    public function __construct(private readonly Connection $db) {}

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

        [$conditions, $params] = DatabaseConditionBuilder::build($policy);

        $whereClause = implode(' AND ', $conditions);

        if ($dryRun) {
            $sql   = 'SELECT COUNT(*) FROM log_entries WHERE ' . $whereClause;
            $count = $this->db->run(static function (\PDO $pdo) use ($sql, $params): int {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return (int) $stmt->fetchColumn();
            });
        } else {
            // Re-running a retention DELETE only removes what is still due.
            $sql   = 'DELETE FROM log_entries WHERE ' . $whereClause;
            $count = $this->db->run(static function (\PDO $pdo) use ($sql, $params): int {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            });
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
        $prefix     = $dryRun ? '[dry-run] ' : '';
        $summary    = "{$prefix}{$count} entries pruned";

        return new RetentionResult(
            policy:     $policy->name,
            pruned:     $count,
            dryRun:     $dryRun,
            durationMs: $durationMs,
            summary:    $summary,
        );
    }

}
