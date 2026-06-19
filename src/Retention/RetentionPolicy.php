<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionPolicy
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $appKey        = null,
        public readonly ?string $appId         = null,
        public readonly ?string $level         = null,
        public readonly ?string $category      = null,
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

        return new \DateTimeImmutable("-{$this->olderThanDays} days");
    }

    /** True if at least one field filter (non-date) is set. */
    public function hasFieldFilters(): bool
    {
        return $this->appKey !== null
            || $this->appId !== null
            || $this->level !== null
            || $this->category !== null
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
        if ($this->olderThanDays !== null) {
            $cutoff    = $this->getCutoffDate();
            $entryTime = $this->parseTimestamp($entry['timestamp'] ?? null);
            if ($entryTime === null || $entryTime >= $cutoff) {
                return false;
            }
        }

        if ($this->appKey !== null && ($entry['app_key'] ?? '') !== $this->appKey) {
            return false;
        }

        if ($this->appId !== null && ($entry['app_id'] ?? '') !== $this->appId) {
            return false;
        }

        if ($this->level !== null && ($entry['level'] ?? '') !== $this->level) {
            return false;
        }

        if ($this->category !== null && ($entry['category'] ?? '') !== $this->category) {
            return false;
        }

        if ($this->messageRegex !== null) {
            $pattern = '/' . str_replace('/', '\\/', $this->messageRegex) . '/u';
            if (!preg_match($pattern, (string) ($entry['message'] ?? ''))) {
                return false;
            }
        }

        if ($this->messageGlob !== null) {
            if (!fnmatch($this->messageGlob, (string) ($entry['message'] ?? ''), FNM_CASEFOLD)) {
                return false;
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────────

    public static function fromArray(array $data): self
    {
        return new self(
            name:          (string) ($data['name']          ?? 'unnamed'),
            appKey:        isset($data['app_key'])        ? (string) $data['app_key']        : null,
            appId:         isset($data['app_id'])         ? (string) $data['app_id']         : null,
            level:         isset($data['level'])          ? (string) $data['level']          : null,
            category:      isset($data['category'])       ? (string) $data['category']       : null,
            messageRegex:  isset($data['message_regex'])  ? (string) $data['message_regex']  : null,
            messageGlob:   isset($data['message_glob'])   ? (string) $data['message_glob']   : null,
            olderThanDays: isset($data['older_than_days']) ? (int)   $data['older_than_days'] : null,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function parseTimestamp(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
