<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Models\LogEntry;

final class RetentionPolicy
{
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
    ) {}

    // ──────────────────────────────────────────────────────────────────────────

    public function getCutoffDate(): ?\DateTimeImmutable
    {
        if ($this->olderThanDays === null) {
            return null;
        }

        // Anchor to today's midnight. "older_than_days: N" means "delete entries
        // strictly older than N days": entries timestamped before today-N 00:00:00
        // are removed; entries from today-N 00:00:00 onwards are kept.
        return (new \DateTimeImmutable('today'))->modify("-{$this->olderThanDays} days");
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
        return !$this->hasFieldFilters() && $this->olderThanDays === null;
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
            olderThanDays: isset($data['older_than_days']) ? (int) $data['older_than_days'] : null,
        );
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
