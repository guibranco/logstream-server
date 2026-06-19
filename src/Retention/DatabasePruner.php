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

        [$conditions, $params] = $this->buildConditions($policy);

        if (empty($conditions)) {
            return 0;
        }

        $sql  = 'DELETE FROM log_entries WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function buildConditions(RetentionPolicy $policy): array
    {
        $conditions = [];
        $params     = [];

        if ($policy->olderThanDays !== null) {
            $conditions[]      = 'timestamp < :cutoff';
            $params[':cutoff'] = $policy->getCutoffDate()->format('Y-m-d H:i:s');
        }

        if ($policy->appKey !== null) {
            $conditions[]       = 'app_key = :app_key';
            $params[':app_key'] = $policy->appKey;
        }

        if ($policy->appId !== null) {
            $conditions[]      = 'app_id = :app_id';
            $params[':app_id'] = $policy->appId;
        }

        if ($policy->level !== null) {
            $conditions[]     = 'level = :level';
            $params[':level'] = $policy->level;
        }

        if ($policy->category !== null) {
            $conditions[]        = 'category = :category';
            $params[':category'] = $policy->category;
        }

        if ($policy->messageRegex !== null) {
            $conditions[]             = 'message REGEXP :message_regex';
            $params[':message_regex'] = $policy->messageRegex;
        }

        if ($policy->messageGlob !== null) {
            $conditions[]            = 'message LIKE :message_glob';
            $params[':message_glob'] = $this->globToLike($policy->messageGlob);
        }

        return [$conditions, $params];
    }

    /**
     * Convert a glob pattern to a SQL LIKE pattern.
     *
     * SQL wildcards (%, _) already present in the glob are escaped first so
     * they are treated as literals, then * → % and ? → _.
     */
    private function globToLike(string $glob): string
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $glob);
        return str_replace(['*', '?'], ['%', '_'], $like);
    }
}
