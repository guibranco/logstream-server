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

        // Message criteria match the rendered message or the template it was
        // rendered from, so a policy can target a template whatever its values.
        if ($policy->messageRegex !== null) {
            $pattern = '/' . str_replace('/', '\\/', $policy->messageRegex) . '/u';
            $matches = static fn(string $text): bool => preg_match($pattern, $text) === 1;
            if (!self::anyMessage($entry, $matches)) {
                return false;
            }
        }

        if ($policy->messageGlob !== null) {
            $glob    = $policy->messageGlob;
            $matches = static fn(string $text): bool => fnmatch($glob, $text, FNM_CASEFOLD);
            if (!self::anyMessage($entry, $matches)) {
                return false;
            }
        }

        return true;
    }

    /** True if the predicate holds for the message or, when present, the message template. */
    private static function anyMessage(array $entry, callable $predicate): bool
    {
        if ($predicate((string) ($entry['message'] ?? ''))) {
            return true;
        }
        $template = $entry['message_template'] ?? null;
        return is_string($template) && $template !== '' && $predicate($template);
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
