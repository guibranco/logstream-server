<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Support;

use LogService\Support\IpRangeList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IpRangeListTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'exact IPv4'          => ['192.168.1.10', '192.168.1.10', true],
            'other IPv4'          => ['192.168.1.10', '192.168.1.11', false],
            'IPv4 /8'             => ['10.0.0.0/8', '10.200.3.4', true],
            'IPv4 /12 inside'     => ['172.16.0.0/12', '172.31.255.255', true],
            'IPv4 /12 outside'    => ['172.16.0.0/12', '172.32.0.1', false],
            'IPv4 /0'             => ['0.0.0.0/0', '8.8.8.8', true],
            'IPv6 /32'            => ['2001:db8::/32', '2001:db8:1::5', true],
            'IPv6 vs IPv4 range'  => ['10.0.0.0/8', '2001:db8::1', false],
            'list'                => ['127.0.0.1, ::1, 10.0.0.0/8', '::1', true],
            'mapped exact'        => ['::ffff:192.0.2.7', '192.0.2.7', true],
            'mapped /120'         => ['::ffff:198.51.100.0/120', '198.51.100.200', true],
            'mapped /120 outside' => ['::ffff:198.51.100.0/120', '198.51.101.1', false],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function contains(string $list, string $ip, bool $expected): void
    {
        self::assertSame($expected, IpRangeList::fromString($list)->contains($ip));
    }

    #[Test]
    public function invalid_ranges_are_skipped(): void
    {
        $list = IpRangeList::fromString('not-an-ip, 10.0.0.0/33, , 10.0.0.1');

        self::assertFalse($list->isEmpty());
        self::assertTrue($list->contains('10.0.0.1'));
        self::assertTrue(IpRangeList::fromString('nope, 1.2.3.4/99')->isEmpty());
        self::assertFalse(IpRangeList::fromString('')->contains('1.2.3.4'));
    }

    /** @return array<string, array{string, ?string}> */
    public static function addresses(): array
    {
        return [
            'IPv4'             => ['10.0.0.7', '10.0.0.7'],
            'IPv4 with port'   => ['10.0.0.7:51234', '10.0.0.7'],
            'IPv6 bracketed'   => ['[2001:db8::1]:51234', '2001:db8::1'],
            'IPv6 bare'        => ['2001:DB8::1', '2001:db8::1'],
            'IPv4-mapped IPv6' => ['[::ffff:10.0.0.7]:1', '10.0.0.7'],
            'empty'            => ['', null],
            'garbage'          => ['not-an-ip', null],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function normalise(string $value, ?string $expected): void
    {
        self::assertSame($expected, IpRangeList::normalise($value));
    }
}
