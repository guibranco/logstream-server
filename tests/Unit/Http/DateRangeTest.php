<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use LogService\Http\DateRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    /** Thursday 12 Nov 2026, 21:30 UTC. */
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-12T21:30:15.250Z');
    }

    /** @return array<string, array{string, string}> */
    public static function rollingRanges(): array
    {
        return [
            '15 minutes' => ['15m', '2026-11-12 21:15:15.250'],
            '4 hours'    => ['4h', '2026-11-12 17:30:15.250'],
            '30 days'    => ['30d', '2026-10-13 21:30:15.250'],
            '60 days'    => ['60d', '2026-09-13 21:30:15.250'],
            '90 days'    => ['90d', '2026-08-14 21:30:15.250'],
            '365 days'   => ['365d', '2025-11-12 21:30:15.250'],
            '2 weeks'    => ['2w', '2026-10-29 21:30:15.250'],
        ];
    }

    #[Test]
    #[DataProvider('rollingRanges')]
    public function rolling_ranges_end_now(string $range, string $expected): void
    {
        self::assertSame($expected, DateRange::resolve($range, self::now()));
    }

    #[Test]
    public function week_starts_on_the_most_recent_sunday_at_midnight(): void
    {
        self::assertSame('2026-11-08 00:00:00.000', DateRange::resolve('week', self::now()));
        // On a Sunday the week starts that same day.
        self::assertSame(
            '2026-11-08 00:00:00.000',
            DateRange::resolve('week', new \DateTimeImmutable('2026-11-08T10:00:00Z')),
        );
    }

    #[Test]
    public function month_starts_on_the_first_at_midnight(): void
    {
        self::assertSame('2026-11-01 00:00:00.000', DateRange::resolve('month', self::now()));
    }

    #[Test]
    public function calendar_ranges_follow_the_given_time_zone(): void
    {
        // 23:30 in São Paulo on Sat 31 Oct is already Sun 1 Nov in UTC.
        $now = new \DateTimeImmutable('2026-11-01T02:30:00Z');
        self::assertSame('2026-10-01 03:00:00.000', DateRange::resolve('month', $now, 'America/Sao_Paulo'));
        self::assertSame('2026-10-25 03:00:00.000', DateRange::resolve('week', $now, 'America/Sao_Paulo'));
        self::assertSame('2026-11-01 00:00:00.000', DateRange::resolve('week', $now));
    }

    #[Test]
    public function now_is_the_current_instant(): void
    {
        self::assertSame('2026-11-12 21:30:15.250', DateRange::resolve('now', self::now()));
    }

    #[Test]
    public function keys_are_case_and_space_insensitive(): void
    {
        self::assertSame('2026-11-01 00:00:00.000', DateRange::resolve(' Month ', self::now()));
    }

    /** @return array<string, array{string}> */
    public static function invalidRanges(): array
    {
        return [
            'unknown word' => ['forever'],
            'zero'         => ['0d'],
            'no unit'      => ['30'],
            'bad unit'     => ['3y'],
            'too long'     => ['9999d'],
        ];
    }

    #[Test]
    #[DataProvider('invalidRanges')]
    public function invalid_ranges_are_rejected(string $range): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::resolve($range, self::now());
    }

    /** @return array<string, array{string}> */
    public static function invalidTimeZones(): array
    {
        return [
            'unknown region' => ['Mars/Olympus'],
            'abbreviation'   => ['CET'],
            'fixed offset'   => ['+01:00'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTimeZones')]
    public function time_zones_must_be_iana_regions(string $tz): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateRange::resolve('week', self::now(), $tz);
    }
}
