<?php

declare(strict_types=1);

namespace LogService\Retention;

final class DatabaseRetentionEngine implements RetentionEngineInterface
{
    public function __construct(private readonly \PDO $pdo) {}

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
            $stmt  = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = (int) $stmt->fetchColumn();
        } else {
            $sql   = 'DELETE FROM log_entries WHERE ' . $whereClause;
            $stmt  = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();
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
