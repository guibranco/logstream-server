<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Models\LogEntry;

final class RetentionPolicy
{
    /** Cutoff pinned by frozen(), so one run uses a single instant; null = computed per call. */
    private ?\DateTimeImmutable $fixedCutoff = null;

    public function __construct(
        public readonly string  $name,
        public readonly ?string $appKey        = null,
        public readonly ?string $appId         = null,
        public readonly ?string $level         = null,
        public readonly ?string $category      = null,
        /** Network transport the entries arrived over: 'tcp' (HTTP) or 'udp'. */
        public readonly ?string $transport     = null,
        public readonly ?string $messageRegex  = null,
        public readonly ?string $messageGlob   = null,
        public readonly ?int    $olderThanDays = null,
        /**
         * Rolling age in hours (now − N hours), for ages shorter than whole days.
         * Mutually exclusive with $olderThanDays.
         */
        public readonly ?int    $olderThanHours = null,
    ) {
        if ($olderThanDays !== null && $olderThanHours !== null) {
            throw new \InvalidArgumentException('Set older_than_days or older_than_hours, not both.');
        }
        if ($olderThanHours !== null && $olderThanHours < 1) {
            throw new \InvalidArgumentException('older_than_hours must be at least 1.');
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @param \DateTimeImmutable|null $now The current time; defaults to the real clock (tests pass one). */
    public function getCutoffDate(?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        // "older_than_hours: N" is a rolling window: entries timestamped before
        // now − N hours are removed, so the cutoff moves with every run.
        if ($this->fixedCutoff !== null) {
            return $this->fixedCutoff;
        }

        if ($this->olderThanHours !== null) {
            // Elapsed time, not wall-clock fields: modify("-N hours") gains or
            // loses an hour across a DST change in the default timezone.
            return ($now ?? new \DateTimeImmutable('now'))->sub(new \DateInterval("PT{$this->olderThanHours}H"));
        }

        if ($this->olderThanDays === null) {
            return null;
        }

        // Anchor to today's midnight. "older_than_days: N" means "delete entries
        // strictly older than N days": entries timestamped before today-N 00:00:00
        // are removed; entries from today-N 00:00:00 onwards are kept.
        $today = $now !== null ? $now->setTime(0, 0) : new \DateTimeImmutable('today');
        return $today->modify("-{$this->olderThanDays} days");
    }

    /**
     * A copy whose cutoff is computed once, now, and kept. A run (or a report)
     * uses it so a rolling hour cutoff does not move forward while it scans.
     */
    public function frozen(): self
    {
        $copy              = clone $this;
        $copy->fixedCutoff = $this->getCutoffDate();
        return $copy;
    }

    /** True when the policy has an age criterion (in days or in hours). */
    public function hasAge(): bool
    {
        return $this->olderThanDays !== null || $this->olderThanHours !== null;
    }

    /** The age criterion for display and logs: "7d", "12h", or null. */
    public function ageLabel(): ?string
    {
        return match (true) {
            $this->olderThanHours !== null => "{$this->olderThanHours}h",
            $this->olderThanDays !== null  => "{$this->olderThanDays}d",
            default                        => null,
        };
    }

    /** True if at least one field filter (non-date) is set. */
    public function hasFieldFilters(): bool
    {
        return $this->appKey !== null
            || $this->appId !== null
            || $this->level !== null
            || $this->category !== null
            || $this->transport !== null
            || $this->messageRegex !== null
            || $this->messageGlob !== null;
    }

    /** True when no filter at all is set — such a policy must never delete anything. */
    public function isEmpty(): bool
    {
        return !$this->hasFieldFilters() && !$this->hasAge();
    }

    /**
     * Returns true when this policy should delete the given entry array.
     * All specified criteria must match simultaneously.
     */
    public function matchesEntry(array $entry): bool
    {
        // One implementation for every retention path, so message criteria
        // also match the message template here.
        return EntryMatcher::matches($this, $entry);
    }

    // ──────────────────────────────────────────────────────────────────────────

    public static function fromArray(array $data): self
    {
        $str = static fn(string $key): ?string
            => (isset($data[$key]) && $data[$key] !== '') ? (string) $data[$key] : null;

        return new self(
            name:          (string) ($data['name'] ?? 'unnamed'),
            appKey:        $str('app_key'),
            appId:         $str('app_id'),
            level:         $str('level'),
            category:      $str('category'),
            transport:     self::parseTransport($str('transport')),
            messageRegex:  $str('message_regex'),
            messageGlob:   $str('message_glob'),
            // Days keep their lenient cast so existing stored policies still load.
            olderThanDays:  isset($data['older_than_days']) ? (int) $data['older_than_days'] : null,
            olderThanHours: self::parseHours($data),
        );
    }

    /**
     * A whole number, or null when absent/empty. Anything else is rejected rather
     * than cast: "12h" silently becoming 12 days would keep or delete the wrong data.
     *
     * @throws \InvalidArgumentException
     */
    private static function parseHours(array $data): ?int
    {
        $key = 'older_than_hours';
        $raw = $data[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1)) {
            return (int) $raw;
        }
        if (is_float($raw) && floor($raw) === $raw) {
            return (int) $raw;
        }
        throw new \InvalidArgumentException("{$key} must be a whole number.");
    }

    /**
     * 'tcp' or 'udp' (case-insensitive). Anything else is rejected rather than
     * dropped: a policy that silently lost its transport would delete entries
     * of every transport.
     *
     * @throws \InvalidArgumentException
     */
    private static function parseTransport(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $transport = strtolower(trim($value));
        if (!in_array($transport, LogEntry::TRANSPORTS, true)) {
            throw new \InvalidArgumentException(
                'transport must be one of: ' . implode(', ', LogEntry::TRANSPORTS),
            );
        }
        return $transport;
    }
}
