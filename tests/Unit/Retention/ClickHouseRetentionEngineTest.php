<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\ClickHouseRetentionEngine;
use LogService\Retention\RetentionPolicy;
use LogService\Tests\Unit\Storage\ClickHouse\FakeClickHouse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** What the engine sends to ClickHouse for each policy shape; live checks run in CI. */
final class ClickHouseRetentionEngineTest extends TestCase
{
    /** @return list<string> the first line of every statement sent */
    private static function statements(FakeClickHouse $fake): array
    {
        return array_map(
            static fn(array $r): string => strtok($r['body'], "\n"),
            $fake->requests,
        );
    }

    /** A policy whose cutoff is today's UTC midnight minus $days. */
    private static function ageOnly(int $days): RetentionPolicy
    {
        return RetentionPolicy::fromArray(['name' => 'age', 'older_than_days' => $days]);
    }

    #[Test]
    public function a_dry_run_only_counts(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['count()' => 42]]);

        $result = (new ClickHouseRetentionEngine($fake->client()))->purge(
            RetentionPolicy::fromArray(['name' => 'debug', 'level' => 'debug', 'older_than_days' => 1]),
            dryRun: true,
        );

        self::assertSame(42, $result->pruned);
        self::assertTrue($result->dryRun);
        self::assertSame('[dry-run] 42 entries pruned', $result->summary);
        self::assertCount(1, $fake->requests);
        foreach (self::statements($fake) as $sql) {
            self::assertDoesNotMatchRegularExpression('/\b(DELETE|ALTER|DROP|TRUNCATE|INSERT)\b/i', $sql, 'a dry run must never write');
        }
    }

    #[Test]
    public function an_empty_policy_sends_nothing(): void
    {
        $fake   = new FakeClickHouse();
        $engine = new ClickHouseRetentionEngine($fake->client());

        self::assertSame(0, $engine->purge(RetentionPolicy::fromArray(['name' => 'empty']))->pruned);
        self::assertSame(0, $engine->prune(RetentionPolicy::fromArray(['name' => 'empty'])));
        self::assertSame([], $fake->requests);
    }

    #[Test]
    public function nothing_to_delete_means_no_write(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['count()' => 0]]);

        self::assertSame(0, (new ClickHouseRetentionEngine($fake->client()))->prune(self::ageOnly(7)));
        self::assertCount(1, $fake->requests);
    }

    #[Test]
    public function a_filtered_policy_uses_a_lightweight_delete(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['count()' => 5]])->respond(200, '');

        $pruned = (new ClickHouseRetentionEngine($fake->client()))->prune(
            RetentionPolicy::fromArray(['name' => 'nginx', 'app_key' => 'nginx', 'older_than_days' => 5]),
        );

        self::assertSame(5, $pruned);
        $delete = $fake->requests[1];
        self::assertSame(
            "DELETE FROM log_entries WHERE timestamp < toDateTime64({r0:String}, 6, 'UTC') AND app_key = {r1:String}",
            $delete['body'],
        );
        self::assertSame('nginx', $delete['query']['param_r1']);
        self::assertSame('2', $delete['query']['lightweight_deletes_sync']);
        self::assertCount(2, $fake->requests, 'no partition is dropped for a filtered policy');
    }

    #[Test]
    public function an_age_only_policy_drops_the_days_before_the_cutoff_whole(): void
    {
        $cutoffDay = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-7 days');
        $old       = $cutoffDay->modify('-2 days')->format('Ymd');
        $last      = $cutoffDay->modify('-1 day')->format('Ymd');
        $kept      = $cutoffDay->format('Ymd');

        $fake = (new FakeClickHouse())
            ->respondRows([['count()' => 30]])                                                // count
            ->respondRows([['partition' => $old], ['partition' => $last], ['partition' => $kept]])
            ->respond(200, '')->respond(200, '')                                           // two DROPs
            ->respondRows([['count()' => 0]]);                                             // nothing left

        $pruned = (new ClickHouseRetentionEngine($fake->client()))->prune(self::ageOnly(7));

        self::assertSame(30, $pruned);
        $statements = self::statements($fake);
        self::assertSame("ALTER TABLE log_entries DROP PARTITION {$old}", $statements[2]);
        self::assertSame("ALTER TABLE log_entries DROP PARTITION {$last}", $statements[3]);
        self::assertCount(5, $statements, "the cutoff day ({$kept}) is kept, and no DELETE is needed");
    }

    #[Test]
    public function an_age_only_policy_deletes_what_is_left_on_the_boundary_day(): void
    {
        $fake = (new FakeClickHouse())
            ->respondRows([['count()' => 12]])
            ->respondRows([])                    // no partition entirely before the cutoff
            ->respondRows([['count()' => 12]])   // so everything is on the boundary day
            ->respond(200, '');

        $pruned = (new ClickHouseRetentionEngine($fake->client()))->prune(
            RetentionPolicy::fromArray(['name' => 'hours', 'older_than_hours' => 6]),
        );

        self::assertSame(12, $pruned);
        self::assertStringStartsWith('DELETE FROM log_entries WHERE timestamp < ', self::statements($fake)[3]);
    }

    #[Test]
    public function purge_reports_what_it_deleted(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['count()' => 3]])->respond(200, '');

        $result = (new ClickHouseRetentionEngine($fake->client()))->purge(
            RetentionPolicy::fromArray(['name' => 'debug', 'level' => 'debug']),
        );

        self::assertSame(['debug', 3, false, '3 entries pruned'], [$result->policy, $result->pruned, $result->dryRun, $result->summary]);
    }
}
