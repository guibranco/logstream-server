<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Pruner for the MariaDB / MySQL storage backend.
 *
 * Builds and executes a parameterised DELETE query from the policy's criteria.
 * message_regex uses MySQL REGEXP; message_glob is converted to a LIKE pattern.
 *
 * Safety: a policy with no conditions at all is silently skipped — it must
 * never delete the entire table.
 */
final class DatabasePruner implements PrunerInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    // ──────────────────────────────────────────────────────────────────────────

    public function prune(RetentionPolicy $policy): int
    {
        if ($policy->isEmpty()) {
            return 0;
        }

        [$conditions, $params] = DatabaseConditionBuilder::build($policy);

        if (empty($conditions)) {
            return 0;
        }

        $sql  = 'DELETE FROM log_entries WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
