<?php

declare(strict_types=1);

namespace LogService\Storage\ClickHouse;

/**
 * Connection settings for the ClickHouse log store (LOG_STORE=clickhouse):
 *
 *   CLICKHOUSE_URL       HTTP interface, default http://127.0.0.1:8123
 *   CLICKHOUSE_DATABASE  default logstream (same name Tools/clickhouse-migration.sh uses)
 *   CLICKHOUSE_USER      default "default"
 *   CLICKHOUSE_PASSWORD  default empty
 *   CLICKHOUSE_TIMEOUT   seconds for connecting and for each response, default 10
 */
final class ClickHouseSettings
{
    public const DEFAULT_URL      = 'http://127.0.0.1:8123';
    public const DEFAULT_DATABASE = 'logstream';
    public const DEFAULT_USER     = 'default';
    public const DEFAULT_TIMEOUT  = 10.0;

    public function __construct(
        public readonly string $url = self::DEFAULT_URL,
        public readonly string $database = self::DEFAULT_DATABASE,
        public readonly string $user = self::DEFAULT_USER,
        public readonly string $password = '',
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
    ) {
        if (!preg_match('#^https?://[^/\s]+/?$#', $url)) {
            throw new \InvalidArgumentException(
                "CLICKHOUSE_URL must be an http(s) URL with no path, like http://127.0.0.1:8123 (got '{$url}')",
            );
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('CLICKHOUSE_TIMEOUT must be a positive number of seconds');
        }
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $timeout = self::setting($env, 'CLICKHOUSE_TIMEOUT');
        if ($timeout !== null && !is_numeric($timeout)) {
            throw new \InvalidArgumentException('CLICKHOUSE_TIMEOUT must be a positive number of seconds');
        }

        return new self(
            url:      self::setting($env, 'CLICKHOUSE_URL') ?? self::DEFAULT_URL,
            database: self::setting($env, 'CLICKHOUSE_DATABASE') ?? self::DEFAULT_DATABASE,
            user:     self::setting($env, 'CLICKHOUSE_USER') ?? self::DEFAULT_USER,
            password: (string) ($env['CLICKHOUSE_PASSWORD'] ?? ''),
            timeout:  $timeout !== null ? (float) $timeout : self::DEFAULT_TIMEOUT,
        );
    }

    /** URL and database, for startup messages (no credentials). */
    public function label(): string
    {
        return rtrim($this->url, '/') . '/' . $this->database;
    }

    /** The trimmed value, or null when unset or empty. */
    private static function setting(array $env, string $key): ?string
    {
        $value = trim((string) ($env[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
