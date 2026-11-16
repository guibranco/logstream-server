<?php

declare(strict_types=1);

namespace LogService\Http;

use LogService\Support\IpRangeList;
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
    private readonly IpRangeList $trusted;

    /**
     * @param list<string> $trustedProxies IPs or CIDR ranges (IPv4 or IPv6)
     */
    public function __construct(array $trustedProxies = [])
    {
        $this->trusted = new IpRangeList($trustedProxies);
    }

    /** Build from a comma-separated list, e.g. "127.0.0.1, 172.16.0.0/12". */
    public static function fromString(string $list): self
    {
        return new self(array_values(array_filter(array_map('trim', explode(',', $list)))));
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $peer = IpRangeList::normalise((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($peer === null || !$this->trusted->contains($peer)) {
            return $peer;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
            foreach ($hops as $hop) {
                $ip = IpRangeList::normalise($hop);
                if ($ip === null) {
                    // Malformed chain — stop trusting it and keep the last good hop.
                    break;
                }
                $peer = $ip;
                if (!$this->trusted->contains($ip)) {
                    return $ip;
                }
            }
            return $peer;
        }

        $realIp = IpRangeList::normalise($request->getHeaderLine('X-Real-IP'));
        return $realIp ?? $peer;
    }
}
