<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\ClickHouseRetentionVolumeAnalyzer;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeReport;
use LogService\Tests\Unit\Storage\ClickHouse\FakeClickHouse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The query sent and how replies map onto RetentionVolumeReport; live checks run in CI. */
final class ClickHouseRetentionVolumeAnalyzerTest extends TestCase
{
    #[Test]
    public function maps_daily_rows_and_part_sizes_onto_the_report(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $fake  = (new FakeClickHouse())
            ->respondRows([[
                'day' => $today, 'cnt' => 10, 'bytes' => 1000, 'due_cnt' => 4, 'due_bytes' => 400,
                'm0_cnt' => 6, 'm0_bytes' => 600, 'd0_cnt' => 4, 'd0_bytes' => 400,
            ]])
            ->respondRows([['data_bytes' => 5000, 'index_bytes' => 700, 'table_rows' => 10, 'engine' => 'ReplacingMergeTree']]);

        $report = (new ClickHouseRetentionVolumeAnalyzer($fake->client()))->analyze(
            [RetentionPolicy::fromArray(['name' => 'debug', 'level' => 'debug', 'older_than_hours' => 1]), RetentionPolicy::fromArray(['name' => 'empty'])],
            days: 3,
        )->toArray();

        self::assertSame(RetentionVolumeReport::SIZE_BASIS_PAYLOAD, $report['size_basis']);
        self::assertSame(10, $report['totals']['count']);
        self::assertSame(400, $report['totals']['due_bytes']);
        self::assertSame(
            ['table' => 'log_entries', 'engine' => 'ReplacingMergeTree', 'data_bytes' => 5000, 'index_bytes' => 700, 'free_bytes' => 0, 'total_bytes' => 5700, 'rows_estimate' => 10],
            $report['storage'],
        );

        $sql = $fake->requests[0]['body'];
        self::assertStringContainsString('FROM log_entries FINAL', $sql);
        self::assertStringContainsString("ifNull((toString(level) = {r0:String}), 0) AS m0", $sql);
        self::assertStringContainsString('(d0) AS due', $sql);
        self::assertStringNotContainsString('m1', $sql, 'empty policies are left out of the query');
    }

    #[Test]
    public function an_unreadable_system_parts_leaves_the_storage_breakdown_out(): void
    {
        $fake = (new FakeClickHouse())->respondRows([])->respond(403, 'Code: 497. DB::Exception: Not enough privileges. (ACCESS_DENIED)');

        $report = (new ClickHouseRetentionVolumeAnalyzer($fake->client()))->analyze([], days: 1)->toArray();

        self::assertNull($report['storage']);
        self::assertSame(0, $report['totals']['count']);
    }
}
