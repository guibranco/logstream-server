<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use LogService\Http\ClientIpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class ClientIpResolverTest extends TestCase
{
    private function request(?string $remoteAddr, array $headers = []): ServerRequest
    {
        $server = $remoteAddr === null ? [] : ['REMOTE_ADDR' => $remoteAddr];
        return new ServerRequest('POST', 'http://localhost/api/logs', $headers, '', '1.1', $server);
    }

    #[Test]
    public function it_returns_the_peer_address_when_no_proxies_are_trusted(): void
    {
        $resolver = new ClientIpResolver();

        self::assertSame('203.0.113.7', $resolver->resolve($this->request('203.0.113.7')));
    }

    #[Test]
    public function it_returns_null_when_the_peer_address_is_missing_or_invalid(): void
    {
        $resolver = new ClientIpResolver();

        self::assertNull($resolver->resolve($this->request(null)));
        self::assertNull($resolver->resolve($this->request('not-an-ip')));
    }

    #[Test]
    public function it_ignores_forwarding_headers_from_untrusted_peers(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $request  = $this->request('203.0.113.7', [
            'X-Forwarded-For' => '198.51.100.1',
            'X-Real-IP'       => '198.51.100.2',
        ]);

        self::assertSame('203.0.113.7', $resolver->resolve($request));
    }

    #[Test]
    public function it_uses_x_forwarded_for_when_the_peer_is_trusted(): void
    {
        $resolver = ClientIpResolver::fromString('172.16.0.0/12');
        $request  = $this->request('172.18.0.5', ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame('198.51.100.1', $resolver->resolve($request));
    }

    #[Test]
    public function it_skips_trusted_hops_and_ignores_spoofed_leftmost_entries(): void
    {
        $resolver = ClientIpResolver::fromString('127.0.0.1, 10.0.0.0/8');
        // Client spoofed "1.1.1.1"; real client is 198.51.100.9, then two trusted proxies.
        $request  = $this->request('127.0.0.1', [
            'X-Forwarded-For' => '1.1.1.1, 198.51.100.9, 10.1.2.3',
        ]);

        self::assertSame('198.51.100.9', $resolver->resolve($request));
    }

    #[Test]
    public function it_falls_back_to_x_real_ip_when_x_forwarded_for_is_absent(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);
        $request  = $this->request('127.0.0.1', ['X-Real-IP' => '198.51.100.4']);

        self::assertSame('198.51.100.4', $resolver->resolve($request));
    }

    #[Test]
    public function it_stops_at_a_malformed_hop_and_returns_the_last_valid_one(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);
        $request  = $this->request('127.0.0.1', ['X-Forwarded-For' => 'garbage, 198.51.100.4']);

        self::assertSame('198.51.100.4', $resolver->resolve($request));
    }

    #[Test]
    public function it_returns_the_proxy_when_every_hop_is_trusted(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1', '10.0.0.0/8']);
        $request  = $this->request('127.0.0.1', ['X-Forwarded-For' => '10.0.0.2']);

        self::assertSame('10.0.0.2', $resolver->resolve($request));
    }

    #[Test]
    public function it_supports_ipv6_peers_and_ranges(): void
    {
        $resolver = new ClientIpResolver(['fd00::/8']);
        $request  = $this->request('fd00::1', ['X-Forwarded-For' => '2001:db8::42']);

        self::assertSame('2001:db8::42', $resolver->resolve($request));
        self::assertSame('2001:db8::1', (new ClientIpResolver())->resolve($this->request('2001:DB8:0::1')));
    }

    #[Test]
    public function it_unwraps_ipv4_mapped_ipv6_addresses(): void
    {
        self::assertSame('203.0.113.7', (new ClientIpResolver())->resolve($this->request('::ffff:203.0.113.7')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function forwardedWithPort(): iterable
    {
        yield 'ipv4 with port' => ['198.51.100.1:5050', '198.51.100.1'];
        yield 'ipv6 with port' => ['[2001:db8::1]:5050', '2001:db8::1'];
        yield 'bracketed ipv6' => ['[2001:db8::1]', '2001:db8::1'];
    }

    #[Test]
    #[DataProvider('forwardedWithPort')]
    public function it_strips_ports_from_forwarded_addresses(string $header, string $expected): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);

        self::assertSame($expected, $resolver->resolve($this->request('127.0.0.1', ['X-Forwarded-For' => $header])));
    }

    #[Test]
    public function it_ignores_invalid_trusted_proxy_entries(): void
    {
        $resolver = new ClientIpResolver(['', 'nonsense', '10.0.0.0/99']);
        $request  = $this->request('10.0.0.1', ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame('10.0.0.1', $resolver->resolve($request));
    }
}
