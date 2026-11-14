<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Demo;

use LogService\Demo\DemoTraffic;
use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DemoTrafficTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach (array_keys(DemoTraffic::SCENARIOS) as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function every_scenario_produces_valid_entries_under_one_trace(string $scenario): void
    {
        for ($run = 0; $run < 20; $run++) {   // scenarios are random: try several runs
            $entries = DemoTraffic::run($scenario, microtime(true));

            self::assertNotEmpty($entries);
            self::assertCount(1, array_unique(array_column($entries, 'trace_id')));
            foreach ($entries as $entry) {
                self::assertArrayHasKey($entry['app_key'], DemoTraffic::SERVICES);
                self::assertContains($entry['level'], LogEntry::LEVELS);
                self::assertNotSame('', $entry['message_template']);
                self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $entry['timestamp']));
            }
        }
    }

    #[Test]
    public function live_runs_end_at_the_given_time_and_steps_are_in_order(): void
    {
        $at      = 1_800_000_000.5;
        $entries = DemoTraffic::run('gateway-timeout', $at);
        $times   = array_map(static fn(array $e) => (float) (new \DateTimeImmutable($e['timestamp']))->format('U.u'), $entries);

        self::assertEqualsWithDelta($at, end($times), 0.001);
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times);
    }

    #[Test]
    public function forced_outcomes_show_their_level(): void
    {
        $levels = static fn(string $s) => array_column(DemoTraffic::run($s, microtime(true)), 'level');

        self::assertContains('critical', $levels('db-pool-exhausted'));
        self::assertContains('error', $levels('gateway-timeout'));
        self::assertContains('error', $levels('email-failure'));
        self::assertContains('warning', $levels('card-declined'));
        self::assertContains('notice', $levels('out-of-stock'));
    }

    #[Test]
    public function backfill_stays_inside_the_window(): void
    {
        $now     = 1_800_000_000.0;
        $entries = DemoTraffic::backfill(2, 30, $now);

        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $t = (float) (new \DateTimeImmutable($entry['timestamp']))->format('U.u');
            self::assertGreaterThanOrEqual($now - 2 * 3600, $t);
            // A trace that starts late may run a few seconds past "now" (retries); never minutes.
            self::assertLessThan($now + 30, $t);
        }
    }

    #[Test]
    public function unknown_scenarios_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DemoTraffic::run('nope', microtime(true));
    }
}
