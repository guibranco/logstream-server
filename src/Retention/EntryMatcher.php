<?php

declare(strict_types=1);

namespace LogService\Retention;

final class EntryMatcher
{
    public static function matches(RetentionPolicy $policy, array $entry): bool
    {
        if ($policy->olderThanDays !== null) {
            $cutoff = $policy->getCutoffDate();
            if (!self::isExpired($entry, $cutoff)) {
                return false;
            }
        }

        if ($policy->appKey !== null && ($entry['app_key'] ?? '') !== $policy->appKey) {
            return false;
        }

        if ($policy->appId !== null && ($entry['app_id'] ?? '') !== $policy->appId) {
            return false;
        }

        if ($policy->level !== null && ($entry['level'] ?? '') !== $policy->level) {
            return false;
        }

        if ($policy->category !== null && ($entry['category'] ?? '') !== $policy->category) {
            return false;
        }

        if ($policy->messageRegex !== null) {
            $pattern = '/' . str_replace('/', '\\/', $policy->messageRegex) . '/u';
            if (!preg_match($pattern, (string) ($entry['message'] ?? ''))) {
                return false;
            }
        }

        if ($policy->messageGlob !== null) {
            if (!fnmatch($policy->messageGlob, (string) ($entry['message'] ?? ''), FNM_CASEFOLD)) {
                return false;
            }
        }

        return true;
    }

    public static function isExpired(array $entry, \DateTimeImmutable $cutoff): bool
    {
        $raw = $entry['timestamp'] ?? null;
        if ($raw === null || $raw === '') {
            return false;
        }
        try {
            $ts = new \DateTimeImmutable((string) $raw);
            return $ts < $cutoff;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function shouldDelete(RetentionPolicy $policy, array $entry): bool
    {
        if ($policy->isEmpty()) {
            return false;
        }
        return self::matches($policy, $entry);
    }
}
