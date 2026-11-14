<?php

declare(strict_types=1);

namespace LogService\Logging;

use LogService\Models\LogEntry;

/**
 * The server's own log entries (startup, PHP deprecations/warnings/errors,
 * ingest and retention problems) stored under a reserved application.
 *
 *   SELF_LOG_ENABLED=true (default) | false
 *     Store the server's own messages as entries of the application
 *     "logstream-server". Console output is unchanged either way.
 *
 *   SELF_LOG_LEVEL=info (default)
 *     Lowest level stored: debug, info, notice, warning, error or critical.
 *     PHP deprecations and notices are "notice", PHP warnings "warning".
 *
 *   SELF_LOG_APP_ID=<hostname> (default)
 *     The entries' app_id, to tell several instances apart.
 *
 * The app_key "logstream-server" is reserved whether or not self-logging is
 * enabled: it has no row in the clients table, no client can be registered
 * with it and POST /api/logs rejects entries that use it.
 */
final class SelfLogConfig
{
    public const APP_KEY = 'logstream-server';

    private const TRUE_VALUES  = ['1', 'true', 'yes', 'on'];
    private const FALSE_VALUES = ['0', 'false', 'no', 'off'];

    public function __construct(
        public readonly bool   $enabled  = true,
        public readonly string $minLevel = 'info',
        public readonly string $appId    = 'default',
    ) {
        if (!in_array($minLevel, LogEntry::LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf(
                "SELF_LOG_LEVEL must be one of %s, got '%s'",
                implode(', ', LogEntry::LEVELS),
                $minLevel,
            ));
        }
        if ($appId === '' || strlen($appId) > 100) {
            throw new \InvalidArgumentException('SELF_LOG_APP_ID must be 1 to 100 characters');
        }
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $appId = trim((string) ($env['SELF_LOG_APP_ID'] ?? ''));
        if ($appId === '') {
            $appId = substr(gethostname() ?: 'default', 0, 100);
        }

        return new self(
            enabled:  self::bool($env, 'SELF_LOG_ENABLED', true),
            minLevel: strtolower(trim((string) ($env['SELF_LOG_LEVEL'] ?? '') ?: 'info')),
            appId:    $appId,
        );
    }

    public static function isReservedAppKey(?string $appKey): bool
    {
        return $appKey !== null && strtolower(trim($appKey)) === self::APP_KEY;
    }

    /** @param array<string, mixed> $env */
    private static function bool(array $env, string $name, bool $default): bool
    {
        $raw = strtolower(trim((string) ($env[$name] ?? '')));
        if ($raw === '') {
            return $default;
        }
        if (in_array($raw, self::TRUE_VALUES, true)) {
            return true;
        }
        if (in_array($raw, self::FALSE_VALUES, true)) {
            return false;
        }
        throw new \InvalidArgumentException("{$name} must be true or false, got '{$raw}'");
    }
}
