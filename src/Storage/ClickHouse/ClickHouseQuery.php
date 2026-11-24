<?php

declare(strict_types=1);

namespace LogService\Storage\ClickHouse;

use LogService\Storage\ContextFilter;
use LogService\Storage\SearchFilters;

/**
 * Compiles {@see SearchFilters} into a ClickHouse WHERE clause.
 *
 * Every value becomes a server-side parameter ({name:String}); only fixed column
 * names from SearchFilters' maps and generated parameter names reach the SQL.
 * The rules follow MariaDBStorage::buildWhere() so both stores answer alike:
 *
 *   exact fields    col = {v} or col IN ({v0}, {v1}, …)
 *   substring       case-insensitive "contains" (MariaDB's LIKE '%…%')
 *   context filter  the value at the path, unquoted, equals the given string;
 *                   context_not also matches entries where the path is absent
 *   context_search  whole words, like MariaDB's FULLTEXT phrase search
 *   date_from/to    inclusive bounds, read as UTC
 */
final class ClickHouseQuery
{
    /** Context as text; null context behaves like an empty one. */
    private const CONTEXT = "ifNull(context, '')";

    /** @var array<string, string> */
    private array $params = [];

    /** @var list<string> */
    private array $where = [];

    private int $counter = 0;

    private function __construct()
    {
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>} Conditions (AND-ed) and parameter values.
     */
    public static function where(SearchFilters $filters): array
    {
        $query = new self();

        foreach ($filters->exact as $column => $values) {
            $query->exact($column, $values);
        }
        foreach ($filters->substring as $column => $term) {
            $query->contains($column, $term);
        }
        foreach ($filters->context as $contextFilter) {
            $query->context($contextFilter);
        }
        if ($filters->contextSearch !== null) {
            $query->contextSearch($filters->contextSearch);
        }
        if ($filters->dateFrom !== null) {
            $query->where[] = 'timestamp >= ' . $query->date($filters->dateFrom);
        }
        if ($filters->dateTo !== null) {
            $query->where[] = 'timestamp <= ' . $query->date($filters->dateTo);
        }

        return [$query->where, $query->params];
    }

    /**
     * Regular expression (RE2) matching $term as whole words: each word of the
     * term, separated by non-word characters, with no word character just
     * before or after. Word characters are letters, digits and "_", as in
     * MariaDB's FULLTEXT parser ("card_declined" is one word). Null when the
     * term has no word at all.
     */
    public static function wholeWordPattern(string $term): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;   // no word to look for
        }

        // Words hold only letters, digits and "_": nothing to escape for RE2.
        return '(?i)(?:^|[^\p{L}\p{N}_])' . implode('[^\p{L}\p{N}_]+', $words) . '(?:[^\p{L}\p{N}_]|$)';
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @param list<string> $values */
    private function exact(string $column, array $values): void
    {
        // level is an Enum8: compare its name, so an unknown level matches nothing
        // instead of failing the query (MariaDB behaves the same way).
        $expr = $column === 'level' ? 'toString(level)' : $column;

        if (count($values) === 1) {
            $this->where[] = "{$expr} = " . $this->param($values[0]);
            return;
        }

        $placeholders = array_map(fn(string $v): string => $this->param($v), $values);
        $this->where[] = "{$expr} IN (" . implode(', ', $placeholders) . ')';
    }

    /** Case-insensitive "contains". */
    private function contains(string $column, string $term): void
    {
        // ASCII terms use lower(col) LIKE, which the ngram index on lower(message)
        // can serve. lower() only folds ASCII, so other terms use the UTF-8-aware
        // function instead.
        if (preg_match('/^[\x00-\x7F]*$/', $term)) {
            $pattern       = '%' . addcslashes(strtolower($term), '\\%_') . '%';
            $this->where[] = "lower({$column}) LIKE " . $this->param($pattern);
            return;
        }

        $this->where[] = "positionCaseInsensitiveUTF8(ifNull({$column}, ''), " . $this->param($term) . ') > 0';
    }

    private function context(ContextFilter $filter): void
    {
        $path   = implode(', ', array_map(fn(string $segment): string => $this->param($segment), $filter->path));
        $exists = 'JSONHas(' . self::CONTEXT . ", {$path})";
        // Strings unquoted, everything else as written in the JSON (42, true, null),
        // like MariaDB's JSON_UNQUOTE(JSON_EXTRACT(…)).
        $actual = 'if(JSONType(' . self::CONTEXT . ", {$path}) = 'String', "
            . 'JSONExtractString(' . self::CONTEXT . ", {$path}), "
            . 'JSONExtractRaw(' . self::CONTEXT . ", {$path}))";
        $value  = $this->param($filter->value);

        $this->where[] = $filter->negate
            ? "(NOT {$exists} OR {$actual} != {$value})"
            : "({$exists} AND {$actual} = {$value})";
    }

    private function contextSearch(string $term): void
    {
        $pattern       = self::wholeWordPattern($term);
        $this->where[] = $pattern === null ? '0' : 'match(' . self::CONTEXT . ', ' . $this->param($pattern) . ')';
    }

    /** Bounds without an offset are UTC; unparseable ones match nothing. */
    private function date(string $value): string
    {
        return 'parseDateTime64BestEffortOrNull(' . $this->param($value) . ", 6, 'UTC')";
    }

    /** Register a String parameter and return its placeholder. */
    private function param(string $value): string
    {
        $name                = 'p' . $this->counter++;
        $this->params[$name] = $value;

        return '{' . $name . ':String}';
    }
}
