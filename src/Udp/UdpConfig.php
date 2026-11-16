<?php

declare(strict_types=1);

namespace LogService\Udp;

use LogService\Support\IpRangeList;

/**
 * UDP ingest settings (UDP_* environment variables).
 */
final class UdpConfig
{
    /** Largest payload a UDP datagram can carry over IPv4. */
    public const MAX_DATAGRAM_BYTES = 65_507;

    /** Shortest accepted nonce: 16 characters ≈ 64 random bits as hex. */
    public const MIN_NONCE_LENGTH = 16;

    public function __construct(
        public readonly bool   $enabled           = true,
        public readonly string $host              = '0.0.0.0',
        public readonly int    $port              = 8082,
        public readonly int    $maxPacketBytes    = 8192,
        public readonly int    $timestampWindowMs = 30_000,
        public readonly int    $nonceTtlSeconds   = 60,
        public readonly int    $nonceCacheMax     = 200_000,
        public readonly string $allowedSources    = '',
    ) {
        if ($port < 1 || $port > 65_535) {
            throw new \InvalidArgumentException("UDP_PORT must be between 1 and 65535, got {$port}");
        }
        if ($maxPacketBytes < 256 || $maxPacketBytes > self::MAX_DATAGRAM_BYTES) {
            throw new \InvalidArgumentException(
                'UDP_MAX_PACKET_SIZE must be between 256 and ' . self::MAX_DATAGRAM_BYTES . ", got {$maxPacketBytes}",
            );
        }
        foreach ([
            'UDP_TIMESTAMP_WINDOW_MS' => $timestampWindowMs,
            'UDP_NONCE_TTL_S'         => $nonceTtlSeconds,
            'UDP_NONCE_CACHE_MAX'     => $nonceCacheMax,
        ] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("{$name} must be a positive integer, got {$value}");
            }
        }
        // A packet must stay "unseen" for as long as it is fresh enough to be accepted,
        // otherwise it could be replayed once its nonce expires.
        if ($nonceTtlSeconds * 1000 < 2 * $timestampWindowMs) {
            throw new \InvalidArgumentException(sprintf(
                'UDP_NONCE_TTL_S (%ds) must cover the whole timestamp window, both sides (2 × UDP_TIMESTAMP_WINDOW_MS = %dms)',
                $nonceTtlSeconds,
                2 * $timestampWindowMs,
            ));
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException("UDP_HOST must be an IP address, got '{$host}'");
        }
        foreach (array_filter(array_map('trim', explode(',', $allowedSources))) as $range) {
            if ((new IpRangeList([$range]))->isEmpty()) {
                throw new \InvalidArgumentException("UDP_ALLOWED_SOURCES: '{$range}' is not an IP address or CIDR range");
            }
        }
    }

    public static function fromEnv(array $env): self
    {
        return new self(
            enabled:           self::bool($env, 'UDP_ENABLED', true),
            host:              trim((string) ($env['UDP_HOST'] ?? '')) ?: '0.0.0.0',
            port:              self::int($env, 'UDP_PORT', 8082),
            maxPacketBytes:    self::int($env, 'UDP_MAX_PACKET_SIZE', 8192),
            timestampWindowMs: self::int($env, 'UDP_TIMESTAMP_WINDOW_MS', 30_000),
            nonceTtlSeconds:   self::int($env, 'UDP_NONCE_TTL_S', 60),
            nonceCacheMax:     self::int($env, 'UDP_NONCE_CACHE_MAX', 200_000),
            allowedSources:    trim((string) ($env['UDP_ALLOWED_SOURCES'] ?? '')),
        );
    }

    public function allowList(): IpRangeList
    {
        return IpRangeList::fromString($this->allowedSources);
    }

    /** "udp://0.0.0.0:8082" — IPv6 hosts in brackets. */
    public function address(): string
    {
        $host = str_contains($this->host, ':') ? "[{$this->host}]" : $this->host;
        return "{$host}:{$this->port}";
    }

    /**
     * UDP and TCP ports are separate, so sharing a number would work, but it
     * is almost always a configuration mistake: reject it.
     */
    public function assertNoPortClash(int $httpPort, int $wsPort): void
    {
        if ($this->enabled && in_array($this->port, [$httpPort, $wsPort], true)) {
            throw new \InvalidArgumentException(
                "UDP_PORT ({$this->port}) must differ from HTTP_PORT ({$httpPort}) and WS_PORT ({$wsPort})",
            );
        }
    }

    private static function int(array $env, string $name, int $default): int
    {
        $raw = trim((string) ($env[$name] ?? ''));
        if ($raw === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new \InvalidArgumentException("{$name} must be an integer, got '{$raw}'");
        }
        return (int) $raw;
    }

    private static function bool(array $env, string $name, bool $default): bool
    {
        $raw = strtolower(trim((string) ($env[$name] ?? '')));
        if ($raw === '') {
            return $default;
        }
        return match ($raw) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException("{$name} must be true or false, got '{$raw}'"),
        };
    }
}
