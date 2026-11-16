<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Udp;

use LogService\Udp\UdpConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UdpConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = UdpConfig::fromEnv([]);

        self::assertTrue($config->enabled);
        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(8082, $config->port);
        self::assertSame(8192, $config->maxPacketBytes);
        self::assertSame(30_000, $config->timestampWindowMs);
        self::assertSame(60, $config->nonceTtlSeconds);
        self::assertSame(200_000, $config->nonceCacheMax);
        self::assertTrue($config->allowList()->isEmpty());
        self::assertSame('0.0.0.0:8082', $config->address());
    }

    #[Test]
    public function reads_the_environment(): void
    {
        $config = UdpConfig::fromEnv([
            'UDP_ENABLED'             => 'false',
            'UDP_HOST'                => '::',
            'UDP_PORT'                => '9000',
            'UDP_MAX_PACKET_SIZE'     => '1400',
            'UDP_TIMESTAMP_WINDOW_MS' => '10000',
            'UDP_NONCE_TTL_S'         => '20',
            'UDP_NONCE_CACHE_MAX'     => '500',
            'UDP_ALLOWED_SOURCES'     => '10.0.0.0/8, 192.168.1.10',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('[::]:9000', $config->address());
        self::assertSame(1400, $config->maxPacketBytes);
        self::assertSame(10_000, $config->timestampWindowMs);
        self::assertSame(20, $config->nonceTtlSeconds);
        self::assertSame(500, $config->nonceCacheMax);
        self::assertTrue($config->allowList()->contains('10.1.2.3'));
        self::assertTrue($config->allowList()->contains('192.168.1.10'));
        self::assertFalse($config->allowList()->contains('192.168.1.11'));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalid(): array
    {
        return [
            'port 0'              => [['UDP_PORT' => '0'], 'UDP_PORT'],
            'port too big'        => [['UDP_PORT' => '70000'], 'UDP_PORT'],
            'port not a number'   => [['UDP_PORT' => 'abc'], 'UDP_PORT'],
            'packet too small'    => [['UDP_MAX_PACKET_SIZE' => '100'], 'UDP_MAX_PACKET_SIZE'],
            'packet too big'      => [['UDP_MAX_PACKET_SIZE' => '65508'], 'UDP_MAX_PACKET_SIZE'],
            'window zero'         => [['UDP_TIMESTAMP_WINDOW_MS' => '0'], 'UDP_TIMESTAMP_WINDOW_MS'],
            'cache zero'          => [['UDP_NONCE_CACHE_MAX' => '0'], 'UDP_NONCE_CACHE_MAX'],
            'ttl below window'    => [['UDP_NONCE_TTL_S' => '30', 'UDP_TIMESTAMP_WINDOW_MS' => '30000'], 'UDP_NONCE_TTL_S'],
            'host not an IP'      => [['UDP_HOST' => 'localhost'], 'UDP_HOST'],
            'bad enabled'         => [['UDP_ENABLED' => 'maybe'], 'UDP_ENABLED'],
            'bad allowed source'  => [['UDP_ALLOWED_SOURCES' => '10.0.0.0/8, not-an-ip'], 'UDP_ALLOWED_SOURCES'],
        ];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function rejects_invalid_settings(array $env, string $variable): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($variable);

        UdpConfig::fromEnv($env);
    }

    #[Test]
    public function port_clash_with_http_or_ws_is_rejected_only_when_enabled(): void
    {
        UdpConfig::fromEnv(['UDP_ENABLED' => 'false', 'UDP_PORT' => '8081'])->assertNoPortClash(8081, 8080);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UDP_PORT (8081) must differ');
        UdpConfig::fromEnv(['UDP_PORT' => '8081'])->assertNoPortClash(8081, 8080);
    }
}
