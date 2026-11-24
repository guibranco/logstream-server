<?php

declare(strict_types=1);

namespace LogService\Storage;

/**
 * Connection settings shared by both MariaDB roles (control plane and log store),
 * read from DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS.
 */
final class MariaDbSettings
{
    public const DEFAULT_HOST     = '127.0.0.1';
    public const DEFAULT_PORT     = 3306;
    public const DEFAULT_DATABASE = 'logservice';
    public const DEFAULT_USER     = 'root';

    public function __construct(
        public readonly string $host = self::DEFAULT_HOST,
        public readonly int $port = self::DEFAULT_PORT,
        public readonly string $database = self::DEFAULT_DATABASE,
        public readonly string $user = self::DEFAULT_USER,
        public readonly string $password = '',
    ) {
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $port = self::setting($env, 'DB_PORT');

        return new self(
            host:     self::setting($env, 'DB_HOST') ?? self::DEFAULT_HOST,
            port:     $port !== null && ctype_digit($port) ? (int) $port : self::DEFAULT_PORT,
            database: self::setting($env, 'DB_NAME') ?? self::DEFAULT_DATABASE,
            user:     self::setting($env, 'DB_USER') ?? self::DEFAULT_USER,
            password: (string) ($env['DB_PASS'] ?? ''),
        );
    }

    /** The trimmed value, or null when unset or empty (an empty line in .env means "use the default"). */
    private static function setting(array $env, string $key): ?string
    {
        $value = trim((string) ($env[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4";
    }

    /** host:port/database, for startup messages (no credentials). */
    public function label(): string
    {
        return "{$this->host}:{$this->port}/{$this->database}";
    }
}
