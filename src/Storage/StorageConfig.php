<?php

declare(strict_types=1);

namespace LogService\Storage;

/**
 * Which backend holds what.
 *
 *   STORAGE_TYPE (control plane): write auth, clients, retention policies and
 *                the retention log. "file" or "mariadb".
 *   LOG_STORE    (log store): where log entries are written, searched and
 *                pruned. "file" or "mariadb"; defaults to STORAGE_TYPE, so
 *                existing deployments keep working without a new setting.
 *
 * Both MariaDB roles use the same DB_* settings.
 */
final class StorageConfig
{
    public const FILE       = 'file';
    public const MARIADB    = 'mariadb';
    public const CLICKHOUSE = 'clickhouse';

    public const CONTROL_PLANES = [self::FILE, self::MARIADB];
    public const LOG_STORES     = [self::FILE, self::MARIADB];

    private function __construct(
        public readonly string $controlPlane,
        public readonly string $logStore,
    ) {
    }

    public static function of(string $controlPlane, ?string $logStore = null): self
    {
        $controlPlane = self::normalise($controlPlane);
        $logStore     = $logStore === null ? $controlPlane : self::normalise($logStore);

        if (!in_array($controlPlane, self::CONTROL_PLANES, true)) {
            throw new \InvalidArgumentException(sprintf(
                "STORAGE_TYPE must be one of: %s (got '%s')",
                implode(', ', self::CONTROL_PLANES),
                $controlPlane,
            ));
        }

        if ($logStore === self::CLICKHOUSE) {
            throw new \InvalidArgumentException(
                'LOG_STORE=clickhouse is not available in this version yet (see guibranco/logstream-server#154)',
            );
        }

        if (!in_array($logStore, self::LOG_STORES, true)) {
            throw new \InvalidArgumentException(sprintf(
                "LOG_STORE must be one of: %s (got '%s')",
                implode(', ', self::LOG_STORES),
                $logStore,
            ));
        }

        return new self($controlPlane, $logStore);
    }

    /**
     * @param array<string, mixed> $env
     * @throws \InvalidArgumentException on unknown or unsupported values
     */
    public static function fromEnv(array $env): self
    {
        $controlPlane = trim((string) ($env['STORAGE_TYPE'] ?? ''));
        $logStore     = trim((string) ($env['LOG_STORE'] ?? ''));

        return self::of(
            $controlPlane === '' ? self::FILE : $controlPlane,
            $logStore === '' ? null : $logStore,
        );
    }

    public function usesMariaDb(): bool
    {
        return $this->controlPlane === self::MARIADB || $this->logStore === self::MARIADB;
    }

    /** True when the control plane and the log store are the same backend (the usual setup). */
    public function isUnified(): bool
    {
        return $this->controlPlane === $this->logStore;
    }

    private static function normalise(string $value): string
    {
        // No case folding: a value that used to fall back to file mode silently
        // ("MariaDB") now fails at boot instead of switching backends.
        return trim($value);
    }
}
