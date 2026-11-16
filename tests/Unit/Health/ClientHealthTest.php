<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Health;

use LogService\Health\ClientHealth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientHealthTest extends TestCase
{
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-17T16:48:22Z');
    }

    #[Test]
    public function never_seen_when_last_seen_is_null(): void
    {
        $health = ClientHealth::evaluate(null, 10, self::now());

        self::assertSame(ClientHealth::NEVER_SEEN, $health->status);
        self::assertNull($health->lastSeenAgo());
        self::assertSame(10, $health->timeoutMinutes);
    }

    #[Test]
    public function never_seen_even_without_timeout(): void
    {
        self::assertSame(ClientHealth::NEVER_SEEN, ClientHealth::evaluate(null, null, self::now())->status);
    }

    #[Test]
    public function active_within_timeout(): void
    {
        $health = ClientHealth::evaluate(new \DateTimeImmutable('2026-04-17T16:45:00Z'), 10, self::now());

        self::assertSame(ClientHealth::ACTIVE, $health->status);
        self::assertSame('3m 22s', $health->lastSeenAgo());
    }

    #[Test]
    public function active_exactly_at_timeout(): void
    {
        $health = ClientHealth::evaluate(self::now()->modify('-10 minutes'), 10, self::now());

        self::assertSame(ClientHealth::ACTIVE, $health->status);
    }

    #[Test]
    public function silent_past_timeout(): void
    {
        $health = ClientHealth::evaluate(self::now()->modify('-10 minutes -1 second'), 10, self::now());

        self::assertSame(ClientHealth::SILENT, $health->status);
        self::assertTrue($health->isSilent());
    }

    #[Test]
    public function active_without_timeout_however_old(): void
    {
        $health = ClientHealth::evaluate(self::now()->modify('-400 days'), null, self::now());

        self::assertSame(ClientHealth::ACTIVE, $health->status);
    }

    #[Test]
    public function future_last_seen_counts_as_zero_seconds(): void
    {
        $health = ClientHealth::evaluate(self::now()->modify('+5 seconds'), 1, self::now());

        self::assertSame(ClientHealth::ACTIVE, $health->status);
        self::assertSame('0s', $health->lastSeenAgo());
    }

    #[Test]
    public function to_array_matches_the_api_shape(): void
    {
        $health = ClientHealth::evaluate(new \DateTimeImmutable('2026-04-17T17:45:00+01:00'), 10, self::now());

        self::assertSame([
            'status'          => 'active',
            'last_seen'       => '2026-04-17T16:45:00+00:00',
            'last_seen_ago'   => '3m 22s',
            'timeout_minutes' => 10,
        ], $health->toArray());
    }

    /** @return array<string, array{int, string}> */
    public static function durations(): array
    {
        return [
            'zero'           => [0, '0s'],
            'seconds'        => [45, '45s'],
            'minute exact'   => [60, '1m 0s'],
            'minutes'        => [202, '3m 22s'],
            'hours'          => [7500, '2h 5m'],
            'days'           => [90000, '1d 1h'],
            'negative'       => [-5, '0s'],
        ];
    }

    #[Test]
    #[DataProvider('durations')]
    public function format_duration(int $seconds, string $expected): void
    {
        self::assertSame($expected, ClientHealth::formatDuration($seconds));
    }

    #[Test]
    public function parse_timestamp_reads_db_datetime_as_utc(): void
    {
        self::assertSame(
            '2026-04-17T16:45:00+00:00',
            ClientHealth::format(ClientHealth::parseTimestamp('2026-04-17 16:45:00')),
        );
        self::assertNull(ClientHealth::parseTimestamp(null));
        self::assertNull(ClientHealth::parseTimestamp(''));
        self::assertNull(ClientHealth::parseTimestamp('not a date'));
    }
}
