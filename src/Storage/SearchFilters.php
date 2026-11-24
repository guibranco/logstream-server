<?php

declare(strict_types=1);

namespace LogService\Storage;

/**
 * Store-agnostic search criteria for GET /api/logs.
 *
 * Each log store turns this into its own query (SQL WHERE clause, in-memory
 * predicate, …), so filter parsing lives in one place and no store has to
 * understand another store's query language.
 *
 * Semantics shared by every store:
 *   - exact fields match when the entry's value equals any of the given values;
 *   - substring fields match case-insensitively anywhere in the value;
 *   - context filters compare a context property as a string (see ContextFilter);
 *   - context_search is free text across context keys and values;
 *   - date_from / date_to are inclusive bounds on the entry timestamp.
 * All given criteria must match (AND).
 */
final class SearchFilters
{
    /** Query parameter → column. An entry matches when its value is one of the given values. */
    public const EXACT_FIELDS = [
        'app_key'          => 'app_key',
        'app_id'           => 'app_id',
        'level'            => 'level',
        'trace_id'         => 'trace_id',
        'batch_id'         => 'batch_id',
        'sender_ip'        => 'sender_ip',
        'transport'        => 'transport',
        'message_template' => 'message_template',
    ];

    /** Query parameter → column. Case-insensitive substring match. */
    public const SUBSTRING_FIELDS = [
        'user_agent' => 'user_agent',
        'category'   => 'category',
        'search'     => 'message',
    ];

    /**
     * @param array<string, list<string>> $exact     Column → accepted values (never empty lists).
     * @param array<string, string>       $substring Column → term.
     * @param list<ContextFilter>         $context
     */
    private function __construct(
        public readonly array $exact,
        public readonly array $substring,
        public readonly array $context,
        public readonly ?string $contextSearch,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
    ) {
    }

    /** No criteria: every entry matches. */
    public static function none(): self
    {
        return new self([], [], [], null, null, null);
    }

    /**
     * Build from the associative form used by the HTTP layer and the tests:
     *   app_key, app_id, level, trace_id, batch_id, sender_ip, transport,
     *   message_template (string or list of strings),
     *   user_agent, category, search (string),
     *   context (list<ContextFilter>), context_search, date_from, date_to (string).
     * Empty values (null, '', []) are ignored, the same way the stores always did.
     *
     * @param array<string, mixed> $filters
     * @throws \InvalidArgumentException on unknown keys or values of the wrong type
     */
    public static function fromArray(array $filters): self
    {
        $known = [
            ...array_keys(self::EXACT_FIELDS),
            ...array_keys(self::SUBSTRING_FIELDS),
            'context', 'context_search', 'date_from', 'date_to',
        ];
        $unknown = array_diff(array_keys($filters), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown search filter: ' . implode(', ', $unknown));
        }

        $exact = [];
        foreach (self::EXACT_FIELDS as $key => $column) {
            $values = self::values($key, $filters[$key] ?? null);
            if ($values !== []) {
                $exact[$column] = $values;
            }
        }

        $substring = [];
        foreach (self::SUBSTRING_FIELDS as $key => $column) {
            $term = self::text($key, $filters[$key] ?? null);
            if ($term !== null) {
                $substring[$column] = $term;
            }
        }

        $context = $filters['context'] ?? [];
        if (!is_array($context) || !array_is_list($context)) {
            throw new \InvalidArgumentException('context must be a list of ContextFilter');
        }
        foreach ($context as $filter) {
            if (!$filter instanceof ContextFilter) {
                throw new \InvalidArgumentException('context must be a list of ContextFilter');
            }
        }

        return new self(
            exact:         $exact,
            substring:     $substring,
            context:       $context,
            contextSearch: self::text('context_search', $filters['context_search'] ?? null),
            dateFrom:      self::text('date_from', $filters['date_from'] ?? null),
            dateTo:        self::text('date_to', $filters['date_to'] ?? null),
        );
    }

    /** True when no criterion is set. */
    public function isEmpty(): bool
    {
        return $this->exact === []
            && $this->substring === []
            && $this->context === []
            && $this->contextSearch === null
            && $this->dateFrom === null
            && $this->dateTo === null;
    }

    /**
     * Back to the associative form accepted by {@see self::fromArray()}: a
     * single exact value as a string, several as a list; unset criteria omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        foreach (self::EXACT_FIELDS as $key => $column) {
            if (isset($this->exact[$column])) {
                $values    = $this->exact[$column];
                $out[$key] = count($values) === 1 ? $values[0] : $values;
            }
        }
        foreach (self::SUBSTRING_FIELDS as $key => $column) {
            if (isset($this->substring[$column])) {
                $out[$key] = $this->substring[$column];
            }
        }

        return array_filter([
            ...$out,
            'context'        => $this->context,
            'context_search' => $this->contextSearch,
            'date_from'      => $this->dateFrom,
            'date_to'        => $this->dateTo,
        ], static fn($v) => $v !== null && $v !== []);
    }

    /**
     * True if a decoded entry (LogEntry::toArray() shape) satisfies every criterion.
     * Used by stores that filter in memory; SQL stores compile the same rules.
     *
     * @param array<string, mixed> $entry
     */
    public function matches(array $entry): bool
    {
        return $this->matchesFields($entry)
            && $this->matchesContext(is_array($entry['context'] ?? null) ? $entry['context'] : null)
            && $this->matchesDates($entry);
    }

    /** Exact and substring criteria on top-level fields. */
    private function matchesFields(array $entry): bool
    {
        foreach ($this->exact as $column => $values) {
            if (!in_array($entry[$column] ?? '', $values, true)) {
                return false;
            }
        }

        foreach ($this->substring as $column => $term) {
            if (!str_contains(strtolower((string) ($entry[$column] ?? '')), strtolower($term))) {
                return false;
            }
        }

        return true;
    }

    /** Context property filters and the free-text context search. */
    private function matchesContext(?array $context): bool
    {
        foreach ($this->context as $contextFilter) {
            if (!$contextFilter->matches($context)) {
                return false;
            }
        }

        return $this->contextSearch === null || ContextFilter::containsTerm($context, $this->contextSearch);
    }

    /** Inclusive date_from / date_to bounds. */
    private function matchesDates(array $entry): bool
    {
        return ($this->dateFrom === null || self::withinBound($entry, $this->dateFrom, before: false))
            && ($this->dateTo === null || self::withinBound($entry, $this->dateTo, before: true));
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function values(string $key, mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            return [$raw];
        }
        if (is_array($raw)) {
            $values = [];
            foreach ($raw as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException("{$key} must be a string or a list of strings");
                }
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            return array_values(array_unique($values));
        }

        throw new \InvalidArgumentException("{$key} must be a string or a list of strings");
    }

    private static function text(string $key, mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException("{$key} must be a string");
        }

        return $raw;
    }

    /** True if the entry's timestamp is on the correct side of the given bound. */
    private static function withinBound(array $entry, string $bound, bool $before): bool
    {
        try {
            $entryTs = new \DateTimeImmutable($entry['timestamp'] ?? 'now');
            // Bounds without an offset are UTC (what the UI and DateRange send),
            // whatever PHP's default time zone is.
            $boundTs = new \DateTimeImmutable($bound, new \DateTimeZone('UTC'));
            return $before ? $entryTs <= $boundTs : $entryTs >= $boundTs;
        } catch (\Throwable) {
            return false;
        }
    }
}
