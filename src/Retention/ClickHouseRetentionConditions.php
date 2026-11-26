<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Compiles a RetentionPolicy into ClickHouse conditions, the counterpart of
 * {@see DatabaseConditionBuilder}. Values are server-side parameters
 * ({name:String}); one instance numbers them, so several policies can share a
 * statement (the volume analyzer).
 *
 * Same rules as MariaDB, and as EntryMatcher where the two differ:
 *   - cutoff: timestamp < the policy's cutoff, read as UTC wall time;
 *   - app_key, app_id, category, transport: equality (no transport recorded
 *     never matches); level compares the enum's name;
 *   - message_regex: RE2, case-sensitive like EntryMatcher (MariaDB's REGEXP
 *     follows the column collation and ignores case);
 *   - message_glob: * and ?, case-insensitive (ILIKE);
 *   - message criteria also match a non-empty message_template.
 */
final class ClickHouseRetentionConditions
{
    /** @var array<string, string> */
    private array $params = [];

    private int $counter = 0;

    /**
     * @param bool $includeCutoff false leaves out the age condition (what the
     *                            policy's filters match at any age).
     * @return list<string> Conditions to AND together; empty for an empty policy.
     */
    public function build(RetentionPolicy $policy, bool $includeCutoff = true): array
    {
        $conditions = [];

        $cutoff = $includeCutoff ? $policy->getCutoffDate() : null;
        if ($cutoff !== null) {
            $conditions[] = 'timestamp < ' . self::cutoff($this->param($cutoff->format('Y-m-d H:i:s')));
        }

        foreach (['app_key' => $policy->appKey, 'app_id' => $policy->appId, 'category' => $policy->category, 'transport' => $policy->transport] as $column => $value) {
            if ($value !== null) {
                $conditions[] = "{$column} = " . $this->param($value);
            }
        }

        if ($policy->level !== null) {
            $conditions[] = 'toString(level) = ' . $this->param($policy->level);
        }

        if ($policy->messageRegex !== null) {
            $pattern      = $this->param($policy->messageRegex);
            $conditions[] = "(match(message, {$pattern}) OR " . self::template("match(message_template, {$pattern})") . ')';
        }

        if ($policy->messageGlob !== null) {
            $like         = $this->param(DatabaseConditionBuilder::globToLike($policy->messageGlob));
            $conditions[] = "(message ILIKE {$like} OR " . self::template("message_template ILIKE {$like}") . ')';
        }

        return $conditions;
    }

    /** @return array<string, string> Every parameter registered so far. */
    public function params(): array
    {
        return $this->params;
    }

    /** "timestamp < cutoff" as ClickHouse reads the 'Y-m-d H:i:s' value: UTC. */
    public static function cutoff(string $placeholder): string
    {
        return "toDateTime64({$placeholder}, 6, 'UTC')";
    }

    /** A test on message_template that only a non-empty template can pass. */
    private static function template(string $test): string
    {
        return "(message_template IS NOT NULL AND message_template != '' AND {$test})";
    }

    private function param(string $value): string
    {
        $name                = 'r' . $this->counter++;
        $this->params[$name] = $value;

        return '{' . $name . ':String}';
    }
}
