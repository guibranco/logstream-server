<?php

declare(strict_types=1);

namespace LogService\Http;

/**
 * Relative date ranges for `GET /api/logs?range=…`, the same keys the UI uses.
 *
 * A range always ends now and is turned into a `date_from` lower bound
 * (no `date_to`), so the same query keeps meaning "the last N" whenever it
 * is sent:
 *
 *   - `<n>m`, `<n>h`, `<n>d`, `<n>w`  rolling window: minutes, hours, days, weeks (e.g. `15m`, `90d`, `365d`)
 *   - `week`                           this week, from 00:00 of the most recent Sunday (today if it is Sunday)
 *   - `month`                          this month, from 00:00 of the 1st
 *   - `now`                            from now on: only entries logged after the request
 *
 * Calendar ranges (`week`, `month`) start at midnight in the given time zone
 * (UTC by default); the bound is returned in UTC, the form storage compares.
 */
final class DateRange
{
    /** Longest rolling window accepted, in days (about 10 years). */
    private const MAX_DAYS = 3660;

    private const UNIT_SECONDS = [
        'm' => 60,
        'h' => 3600,
        'd' => 86400,
        'w' => 604800,
    ];

    /**
     * Lower bound for a range key, as `Y-m-d H:i:s.v` in UTC.
     *
     * @throws \InvalidArgumentException when the key or time zone is not recognised
     */
    public static function resolve(string $range, \DateTimeImmutable $now, ?string $timezone = null): string
    {
        $from = self::start(strtolower(trim($range)), $now, self::zone($timezone));

        return $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private static function start(string $range, \DateTimeImmutable $now, \DateTimeZone $zone): \DateTimeImmutable
    {
        $local = $now->setTimezone($zone);

        switch ($range) {
            case 'now':
                return $now;
            case 'week':
                // 'w' is 0 on Sunday, so this goes back to today on a Sunday.
                return $local->setTime(0, 0)->modify('-' . (int)$local->format('w') . ' days');
            case 'month':
                return $local->setDate((int)$local->format('Y'), (int)$local->format('n'), 1)->setTime(0, 0);
        }

        if (preg_match('/^(\d{1,7})([mhdw])$/', $range, $m) === 1) {
            $seconds = (int)$m[1] * self::UNIT_SECONDS[$m[2]];
            if ($seconds > 0 && $seconds <= self::MAX_DAYS * 86400) {
                return $now->modify("-{$seconds} seconds");
            }
        }

        throw new \InvalidArgumentException(
            "Invalid range '{$range}'. Use <n>m, <n>h, <n>d or <n>w (e.g. 15m, 90d), week, month or now.",
        );
    }

    private static function zone(?string $timezone): \DateTimeZone
    {
        if ($timezone === null || trim($timezone) === '') {
            return new \DateTimeZone('UTC');
        }
        // IANA region names only: abbreviations (CET) and fixed offsets (+01:00)
        // would put calendar boundaries on the wrong side of DST changes.
        $name = trim($timezone);
        if (!in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Invalid tz '{$timezone}'. Use an IANA time zone such as Europe/Dublin.");
        }

        return new \DateTimeZone($name);
    }
}
