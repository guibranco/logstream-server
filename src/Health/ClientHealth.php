<?php

declare(strict_types=1);

namespace LogService\Health;

/**
 * Health status of a registered client, worked out from when it was last
 * seen and its heartbeat timeout.
 *
 *   never_seen — no entry has ever been accepted from it
 *   silent     — seen before, but nothing for longer than the timeout
 *   active     — seen within the timeout, or seen and no timeout configured
 */
final class ClientHealth
{
    public const ACTIVE     = 'active';
    public const SILENT     = 'silent';
    public const NEVER_SEEN = 'never_seen';

    /** Largest accepted heartbeat timeout: 30 days. */
    public const MAX_TIMEOUT_MINUTES = 43200;

    private function __construct(
        public readonly string              $status,
        public readonly ?\DateTimeImmutable $lastSeen,
        public readonly ?int                $timeoutMinutes,
        /** Seconds since last seen (never negative), or null when never seen. */
        public readonly ?int                $secondsSinceSeen,
    ) {}

    public static function evaluate(
        ?\DateTimeImmutable $lastSeen,
        ?int                $timeoutMinutes,
        \DateTimeImmutable  $now,
    ): self {
        if ($lastSeen === null) {
            return new self(self::NEVER_SEEN, null, $timeoutMinutes, null);
        }

        $elapsed = max(0, $now->getTimestamp() - $lastSeen->getTimestamp());
        $silent  = $timeoutMinutes !== null && $timeoutMinutes > 0 && $elapsed > $timeoutMinutes * 60;

        return new self($silent ? self::SILENT : self::ACTIVE, $lastSeen, $timeoutMinutes, $elapsed);
    }

    public function isSilent(): bool
    {
        return $this->status === self::SILENT;
    }

    /** e.g. "3m 22s", "2h 5m", "4d 1h" — or null when never seen. */
    public function lastSeenAgo(): ?string
    {
        return $this->secondsSinceSeen === null ? null : self::formatDuration($this->secondsSinceSeen);
    }

    /** Two most significant units of a duration: "45s", "3m 22s", "2h 5m", "4d 1h". */
    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $units   = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $parts   = [];

        foreach ($units as $suffix => $size) {
            if ($seconds >= $size || ($parts !== [])) {
                $parts[]  = intdiv($seconds, $size) . $suffix;
                $seconds %= $size;
            }
            if (count($parts) === 2) {
                break;
            }
        }

        return $parts === [] ? '0s' : implode(' ', $parts);
    }

    /**
     * Parses a stored DATETIME ("2026-04-17 16:45:00", always UTC) or an
     * RFC 3339 string. Null, empty or unparsable values give null.
     */
    public static function parseTimestamp(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /** RFC 3339 in UTC, e.g. "2026-04-17T16:45:00+00:00". */
    public static function format(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339);
    }

    /** @return array{status: string, last_seen: ?string, last_seen_ago: ?string, timeout_minutes: ?int} */
    public function toArray(): array
    {
        return [
            'status'          => $this->status,
            'last_seen'       => self::format($this->lastSeen),
            'last_seen_ago'   => $this->lastSeenAgo(),
            'timeout_minutes' => $this->timeoutMinutes,
        ];
    }
}
