<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionVolumeReportTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-11-12');
    }

    #[Test]
    public function it_fills_one_bucket_per_day_oldest_first(): void
    {
        $report = RetentionVolumeReport::fromDailyTotals([], [], 3, RetentionVolumeReport::SIZE_BASIS_FILE, $this->today);

        self::assertSame(
            [['2026-11-10', 2], ['2026-11-11', 1], ['2026-11-12', 0]],
            array_map(fn(array $b) => [$b['date'], $b['age_days']], $report->buckets),
        );
        self::assertSame(0, $report->buckets[0]['count']);
    }

    #[Test]
    public function it_places_days_in_buckets_and_folds_the_rest_into_older_and_future(): void
    {
        $daily = [
            '2026-11-12' => ['count' => 5, 'bytes' => 50],
            '2026-11-11' => ['count' => 3, 'bytes' => 30, 'due_count' => 1, 'due_bytes' => 10],
            '2026-10-01' => ['count' => 7, 'bytes' => 70, 'due_count' => 7, 'due_bytes' => 70],
            '2026-11-13' => ['count' => 2, 'bytes' => 20],
        ];

        $report = RetentionVolumeReport::fromDailyTotals([], $daily, 2, RetentionVolumeReport::SIZE_BASIS_FILE, $this->today);

        self::assertSame(3, $report->buckets[0]['count']);
        self::assertSame(1, $report->buckets[0]['due_count']);
        self::assertSame(5, $report->buckets[1]['count']);
        self::assertSame(['count' => 7, 'bytes' => 70, 'due_count' => 7, 'due_bytes' => 70], $report->older);
        self::assertSame(2, $report->future['count']);
        self::assertSame(['count' => 17, 'bytes' => 170, 'due_count' => 8, 'due_bytes' => 80], $report->totals);
    }

    #[Test]
    public function it_sums_policy_stats_and_computes_matched_share(): void
    {
        $policies = [
            RetentionPolicy::fromArray(['name' => 'debug', 'level' => 'debug', 'older_than_days' => 2]),
            RetentionPolicy::fromArray(['name' => 'empty']),
        ];
        $daily = [
            '2026-11-12' => ['count' => 6, 'bytes' => 60, 'policies' => ['debug' => ['matched_count' => 3, 'matched_bytes' => 30]]],
            '2026-11-01' => ['count' => 4, 'bytes' => 40, 'policies' => [
                'debug'   => ['matched_count' => 2, 'matched_bytes' => 20, 'due_count' => 2, 'due_bytes' => 20],
                'unknown' => ['matched_count' => 9],
            ]],
        ];

        $report = RetentionVolumeReport::fromDailyTotals($policies, $daily, 30, RetentionVolumeReport::SIZE_BASIS_FILE, $this->today);

        [$debug, $empty] = $report->policies;
        self::assertSame('debug', $debug['name']);
        self::assertSame(2, $debug['older_than_days']);
        self::assertTrue($debug['active']);
        self::assertSame(5, $debug['matched_count']);
        self::assertSame(50, $debug['matched_bytes']);
        self::assertSame(2, $debug['due_count']);
        self::assertSame(0.5, $debug['matched_share']);
        self::assertFalse($empty['active']);
        self::assertSame(0, $empty['matched_count']);
        self::assertCount(2, $report->policies);
    }

    #[Test]
    public function it_serialises_to_the_api_shape(): void
    {
        $out = RetentionVolumeReport::fromDailyTotals([], [], 1, RetentionVolumeReport::SIZE_BASIS_PAYLOAD, $this->today)->toArray();

        self::assertSame(
            ['generated_at', 'days', 'size_basis', 'buckets', 'older', 'future', 'policies', 'totals'],
            array_keys($out),
        );
        self::assertSame('payload', $out['size_basis']);
        self::assertSame(1, $out['days']);
    }
}
