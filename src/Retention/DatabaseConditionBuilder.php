<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Translates a RetentionPolicy's criteria into a parameterised SQL WHERE
 * clause, shared by DatabasePruner and DatabaseRetentionEngine.
 *
 * message_regex uses MySQL REGEXP; message_glob is converted to a LIKE pattern.
 * Both match the rendered message or the template it was rendered from
 * (message_template, migration 007). Each column gets its own parameter because
 * native prepared statements do not allow a named parameter to appear twice.
 */
final class DatabaseConditionBuilder
{
    /**
     * $suffix is appended to every parameter name so several policies can be
     * combined in one statement; $includeCutoff = false leaves out the
     * timestamp condition (used to measure what a policy's filters match at
     * any age).
     *
     * @return array{0: string[], 1: array<string, string>} [conditions, params]
     */
    public static function build(RetentionPolicy $policy, string $suffix = '', bool $includeCutoff = true): array
    {
        $conditions = [];
        $params     = [];
        $p          = static fn(string $name): string => ':' . $name . $suffix;

        if ($includeCutoff && $policy->olderThanDays !== null) {
            $conditions[]           = 'timestamp < ' . $p('cutoff');
            $params[$p('cutoff')]   = $policy->getCutoffDate()->format('Y-m-d H:i:s');
        }

        if ($policy->appKey !== null) {
            $conditions[]           = 'app_key = ' . $p('app_key');
            $params[$p('app_key')]  = $policy->appKey;
        }

        if ($policy->appId !== null) {
            $conditions[]           = 'app_id = ' . $p('app_id');
            $params[$p('app_id')]   = $policy->appId;
        }

        if ($policy->level !== null) {
            $conditions[]           = 'level = ' . $p('level');
            $params[$p('level')]    = $policy->level;
        }

        if ($policy->category !== null) {
            $conditions[]           = 'category = ' . $p('category');
            $params[$p('category')] = $policy->category;
        }

        if ($policy->messageRegex !== null) {
            $conditions[] = '(message REGEXP ' . $p('message_regex')
                . ' OR message_template REGEXP ' . $p('message_template_regex') . ')';
            $params[$p('message_regex')]          = $policy->messageRegex;
            $params[$p('message_template_regex')] = $policy->messageRegex;
        }

        if ($policy->messageGlob !== null) {
            $like         = self::globToLike($policy->messageGlob);
            $conditions[] = '(message LIKE ' . $p('message_glob')
                . ' OR message_template LIKE ' . $p('message_template_glob') . ')';
            $params[$p('message_glob')]          = $like;
            $params[$p('message_template_glob')] = $like;
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
