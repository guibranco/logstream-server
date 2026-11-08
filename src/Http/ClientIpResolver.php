<?php

declare(strict_types=1);

namespace LogService\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the IP address of the client that sent a request.
 *
 * By default the TCP peer address (REMOTE_ADDR) is used. When the server sits
 * behind one or more reverse proxies, list them in TRUSTED_PROXIES: if the
 * peer is trusted, the X-Forwarded-For chain is walked right-to-left, skipping
 * trusted hops, and the first untrusted address is returned. X-Real-IP is used
 * as a fallback when the trusted peer sends no X-Forwarded-For.
 *
 * Forwarding headers from untrusted peers are ignored, so clients cannot
 * spoof their address.
 */
final class ClientIpResolver
{
    /** @var list<array{0: string, 1: int}> Packed network address + prefix length */
    private array $trusted = [];

    /**
     * @param list<string> $trustedProxies IPs or CIDR ranges (IPv4 or IPv6)
     */
    public function __construct(array $trustedProxies = [])
    {
        foreach ($trustedProxies as $proxy) {
            $parsed = self::parseRange(trim($proxy));
            if ($parsed !== null) {
                $this->trusted[] = $parsed;
            }
        }
    }

    /** Build from a comma-separated list, e.g. "127.0.0.1, 172.16.0.0/12". */
    public static function fromString(string $list): self
    {
        return new self(array_values(array_filter(array_map('trim', explode(',', $list)))));
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $peer = self::normalise((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($peer === null || !$this->isTrusted($peer)) {
            return $peer;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
            foreach ($hops as $hop) {
                $ip = self::normalise($hop);
                if ($ip === null) {
                    // Malformed chain — stop trusting it and keep the last good hop.
                    break;
                }
                $peer = $ip;
                if (!$this->isTrusted($ip)) {
                    return $ip;
                }
            }
            return $peer;
        }

        $realIp = self::normalise($request->getHeaderLine('X-Real-IP'));
        return $realIp ?? $peer;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function isTrusted(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->trusted as [$network, $bits]) {
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
            return [$packed, $max];
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }

        return [$packed, (int) $bits];
    }

    /**
     * Validate an address and return its canonical form; strips an optional
     * port ("1.2.3.4:5678", "[::1]:5678") and unwraps IPv4-mapped IPv6.
     */
    private static function normalise(string $value): ?string
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
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xFF\xFF")) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop($packed);
    }
}
