<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Translates a RetentionPolicy's criteria into a parameterised SQL WHERE
 * clause, shared by DatabasePruner and DatabaseRetentionEngine.
 *
 * message_regex uses MySQL REGEXP; message_glob is converted to a LIKE pattern.
 */
final class DatabaseConditionBuilder
{
    /**
     * @return array{0: string[], 1: array<string, string>} [conditions, params]
     */
    public static function build(RetentionPolicy $policy): array
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
            $params[':message_glob'] = self::globToLike($policy->messageGlob);
        }

        return [$conditions, $params];
    }

    /**
     * Convert a glob pattern to a SQL LIKE pattern.
     *
     * SQL wildcards (%, _) already present in the glob are escaped first so
     * they are treated as literals, then * → % and ? → _.
     */
    private static function globToLike(string $glob): string
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $glob);
        return str_replace(['*', '?'], ['%', '_'], $like);
    }
}
