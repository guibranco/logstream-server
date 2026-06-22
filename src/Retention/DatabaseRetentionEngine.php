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

        [$conditions, $params] = $this->buildConditions($policy);

        if (empty($conditions)) {
            return new RetentionResult(
                policy:     $policy->name,
                pruned:     0,
                dryRun:     $dryRun,
                durationMs: 0,
                summary:    'No conditions built — nothing to do',
            );
        }

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

    private function globToLike(string $glob): string
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $glob);
        return str_replace(['*', '?'], ['%', '_'], $like);
    }
}
