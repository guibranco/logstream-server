<?php

declare(strict_types=1);

namespace LogService\Storage;

/**
 * A single equality condition on a property inside an entry's `context`.
 *
 * Built from query parameters:
 *   context[order.id]=42        → context.order.id == "42"
 *   context_not[user.role]=bot  → context.user.role != "bot" (or absent)
 *
 * Dots in the key walk into nested objects. Values are compared as strings,
 * with scalars normalised the same way JSON_UNQUOTE renders them
 * (true/false/null, numbers in their plain form).
 */
final class ContextFilter
{
    public const MAX_FILTERS  = 10;
    public const MAX_PATH_LEN = 200;

    public const MIN_SEARCH_LEN = 3;
    public const MAX_SEARCH_LEN = 200;

    /** @param list<string> $path */
    private function __construct(
        public readonly array $path,
        public readonly string $value,
        public readonly bool $negate,
    ) {
    }

    /**
     * @return list<self>
     * @throws \InvalidArgumentException on malformed keys or too many filters
     */
    public static function fromQuery(mixed $include, mixed $exclude): array
    {
        $filters = [
            ...self::parseGroup($include, negate: false),
            ...self::parseGroup($exclude, negate: true),
        ];

        if (count($filters) > self::MAX_FILTERS) {
            throw new \InvalidArgumentException(
                sprintf('At most %d context filters are allowed', self::MAX_FILTERS),
            );
        }

        return $filters;
    }

    /** True if the given decoded context satisfies this filter. */
    public function matches(?array $context): bool
    {
        $found  = false;
        $actual = $this->resolve($context, $found);
        $equal  = $found && $actual !== null && $actual === $this->value;

        return $this->negate ? !$equal : $equal;
    }

    /** MariaDB JSON path with every segment quoted, e.g. $."order"."id". */
    public function jsonPath(): string
    {
        $segments = array_map(
            fn(string $s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"',
            $this->path,
        );

        return '$.' . implode('.', $segments);
    }

    /**
     * Validate the free-text `context_search` term.
     *
     * The minimum length matches MariaDB's default `innodb_ft_min_token_size`:
     * shorter words are never indexed, so they could only ever match nothing.
     *
     * @throws \InvalidArgumentException
     */
    public static function parseSearchTerm(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('context_search must be a string');
        }

        $term = trim($raw);
        $len  = mb_strlen($term);
        if ($len < self::MIN_SEARCH_LEN || $len > self::MAX_SEARCH_LEN) {
            throw new \InvalidArgumentException(sprintf(
                'context_search must be between %d and %d characters',
                self::MIN_SEARCH_LEN,
                self::MAX_SEARCH_LEN,
            ));
        }

        return $term;
    }

    /** Boolean-mode FULLTEXT phrase for a search term: operators inside quotes are literal. */
    public static function fulltextPhrase(string $term): string
    {
        return '"' . trim(str_replace('"', ' ', $term)) . '"';
    }

    /** Case-insensitive substring match on any key or scalar value, at any depth. */
    public static function containsTerm(?array $context, string $term): bool
    {
        return $context !== null && self::nodeContains($context, mb_strtolower($term));
    }

    /** Render a scalar context value as a comparable string; null for arrays/objects. */
    public static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default          => null,
        };
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<self> */
    private static function parseGroup(mixed $group, bool $negate): array
    {
        if ($group === null || $group === '') {
            return [];
        }
        if (!is_array($group)) {
            throw new \InvalidArgumentException('Context filters must use the form context[path]=value');
        }

        $filters = [];
        foreach ($group as $key => $value) {
            $key = (string) $key;
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Invalid value for context filter '{$key}'");
            }
            if ($key === '' || strlen($key) > self::MAX_PATH_LEN) {
                throw new \InvalidArgumentException('Invalid context filter path');
            }

            $path = explode('.', $key);
            if (in_array('', $path, true)) {
                throw new \InvalidArgumentException("Invalid context filter path '{$key}'");
            }

            $filters[] = new self($path, $value, $negate);
        }

        return $filters;
    }

    private static function nodeContains(array $node, string $needle): bool
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && str_contains(mb_strtolower($key), $needle)) {
                return true;
            }

            $matches = is_array($value)
                ? self::nodeContains($value, $needle)
                : str_contains(mb_strtolower((string) self::stringify($value)), $needle);

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function resolve(?array $context, bool &$found): ?string
    {
        $node = $context;
        foreach ($this->path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $found = false;
                return null;
            }
            $node = $node[$segment];
        }

        $found = true;
        return self::stringify($node);
    }
}
