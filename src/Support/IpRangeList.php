<?php

declare(strict_types=1);

namespace LogService\Support;

/**
 * A list of IP addresses and CIDR ranges (IPv4 and IPv6), e.g. for
 * TRUSTED_PROXIES or UDP_ALLOWED_SOURCES.
 */
final class IpRangeList
{
    /** First 12 bytes of an IPv4-mapped IPv6 address (::ffff:a.b.c.d). */
    private const MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    /** @var list<array{0: string, 1: int}> Packed network address + prefix length */
    private array $ranges = [];

    /**
     * @param list<string> $ranges IPs or CIDR ranges; invalid ones are skipped.
     */
    public function __construct(array $ranges = [])
    {
        foreach ($ranges as $range) {
            $parsed = self::parseRange(trim($range));
            if ($parsed !== null) {
                $this->ranges[] = $parsed;
            }
        }
    }

    /** Build from a comma-separated list, e.g. "127.0.0.1, 172.16.0.0/12". */
    public static function fromString(string $list): self
    {
        return new self(array_values(array_filter(array_map('trim', explode(',', $list)))));
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    /** Whether a canonical IP (see normalise()) is in one of the ranges. */
    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->ranges as [$network, $bits]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate an address and return its canonical form; strips an optional
     * port ("1.2.3.4:5678", "[::1]:5678") and unwraps IPv4-mapped IPv6.
     */
    public static function normalise(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $m)) {
            $value = $m[1];
        } elseif (substr_count($value, ':') === 1) {
            $value = explode(':', $value, 2)[0];
        }

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if (strlen($packed) === 16 && str_starts_with($packed, self::MAPPED_PREFIX)) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop($packed);
    }

    /** @return array{0: string, 1: int}|null */
    private static function parseRange(string $range): ?array
    {
        if ($range === '') {
            return null;
        }

        [$addr, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $packed = @inet_pton($addr);
        if ($packed === false) {
            return null;
        }

        $max = strlen($packed) * 8;
        if ($bits === null) {
            $bits = (string) $max;
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        $bits = (int) $bits;

        // ::ffff:a.b.c.d/96+ — store as the IPv4 range, because normalise()
        // turns IPv4-mapped peers into plain IPv4 before they are matched.
        if (strlen($packed) === 16 && $bits >= 96 && str_starts_with($packed, self::MAPPED_PREFIX)) {
            return [substr($packed, 12), $bits - 96];
        }

        return [$packed, $bits];
    }
}
