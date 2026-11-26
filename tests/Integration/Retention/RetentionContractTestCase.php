<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Retention;

use LogService\Models\LogEntry;
use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\SearchFilters;
use LogService\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Retention must behave the same on every log store: the same seeded entries,
 * the same policies, the same counts and the same survivors. Ages are relative
 * to today (UTC) and within ClickHouse's 35-day TTL.
 */
abstract class RetentionContractTestCase extends TestCase
{
    /** A fresh, empty storage for each test, and the retention services over it. */
    abstract protected function storage(): StorageInterface;

    abstract protected function engine(): RetentionEngineInterface;

    abstract protected function pruner(): PrunerInterface;

    abstract protected function analyzer(): RetentionVolumeAnalyzerInterface;

    protected function setUp(): void
    {
        $at = static fn(int $daysAgo, string $time = '12:00:00'): string =>
            (new \DateTimeImmutable("today -{$daysAgo} days", new \DateTimeZone('UTC')))->format("Y-m-d\\T{$time}.000000\\Z");

        $entries = [
            'A' => ['app_key' => 'nginx', 'level' => 'info', 'transport' => 'udp', 'category' => 'access',
                    'message' => 'GET /health 200', 'message_template' => 'GET {path} {status}', 'timestamp' => $at(10)],
            'B' => ['app_key' => 'nginx', 'level' => 'info', 'transport' => 'udp', 'category' => 'access',
                    'message' => 'GET /api 200', 'message_template' => 'GET {path} {status}', 'timestamp' => $at(3)],
            'C' => ['app_key' => 'billing-api', 'level' => 'debug', 'transport' => 'tcp', 'category' => 'general',
                    'message' => 'Health check ok', 'timestamp' => $at(10, '08:00:00')],
            'D' => ['app_key' => 'billing-api', 'level' => 'error', 'transport' => 'tcp', 'category' => 'Payments',
                    'message' => 'Card declined', 'message_template' => 'Card declined for {orderId}', 'timestamp' => $at(10, '20:00:00')],
            'E' => ['app_key' => 'billing-api', 'level' => 'debug', 'transport' => null, 'category' => 'general',
                    'message' => 'Debug dump', 'timestamp' => $at(1)],
            'F' => ['app_key' => 'users-api', 'level' => 'info', 'transport' => 'udp', 'category' => 'general',
                    'message' => 'Login ok', 'timestamp' => $at(0, '00:00:01')],
        ];

        $seeded = [];
        foreach ($entries as $key => $fields) {
            $seeded[] = LogEntry::fromArray($fields + [
                'id' => '01JDXS5G8QW3Z6V9N2M4K7H1R' . $key, 'trace_id' => "trace-{$key}", 'batch_id' => null,
                'app_id' => 'vinhedo5', 'user_agent' => null, 'context' => null, 'message_template' => null,
            ]);
        }

        $this->storage()->saveMany($seeded);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> policy → keys it deletes */
    public static function policies(): iterable
    {
        yield 'older than 7 days (whole days)'   => [['older_than_days' => 7], ['A', 'C', 'D']];
        yield 'older than 100 hours (rolling)'   => [['older_than_hours' => 100], ['A', 'C', 'D']];
        yield 'app_key, older than 2 days'       => [['app_key' => 'nginx', 'older_than_days' => 2], ['A', 'B']];
        yield 'level at any age'                 => [['level' => 'debug'], ['C', 'E']];
        yield 'transport, older than 5 days'     => [['transport' => 'udp', 'older_than_days' => 5], ['A']];
        yield 'no transport never matches'       => [['transport' => 'tcp'], ['C', 'D']];
        yield 'category and level'               => [['category' => 'Payments', 'level' => 'error'], ['D']];
        yield 'regex on the message'             => [['message_regex' => '^Health'], ['C']];
        yield 'glob on the template'             => [['message_glob' => '*declined for*'], ['D']];
        yield 'glob ignores case'                => [['message_glob' => 'get /HEALTH*'], ['A']];
        yield 'nothing old enough'               => [['older_than_days' => 30], []];
    }

    /** @param list<string> $deleted */
    #[Test]
    #[DataProvider('policies')]
    public function a_dry_run_counts_without_deleting(array $policy, array $deleted): void
    {
        $result = $this->engine()->purge(self::policy($policy), dryRun: true);

        self::assertSame(count($deleted), $result->pruned);
        self::assertTrue($result->dryRun);
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $this->survivors(), 'a dry run must never delete');
    }

    /** @param list<string> $deleted */
    #[Test]
    #[DataProvider('policies')]
    public function purge_deletes_exactly_what_the_policy_matches(array $policy, array $deleted): void
    {
        $result = $this->engine()->purge(self::policy($policy));

        self::assertSame(count($deleted), $result->pruned, 'reported count');
        self::assertSame(array_values(array_diff(['A', 'B', 'C', 'D', 'E', 'F'], $deleted)), $this->survivors());

        // Running it again finds nothing more.
        self::assertSame(0, $this->engine()->purge(self::policy($policy))->pruned);
    }

    #[Test]
    public function prune_deletes_like_purge(): void
    {
        self::assertSame(2, $this->pruner()->prune(self::policy(['level' => 'debug'])));
        self::assertSame(['A', 'B', 'D', 'F'], $this->survivors());
    }

    #[Test]
    public function an_empty_policy_deletes_nothing(): void
    {
        self::assertSame(0, $this->engine()->purge(RetentionPolicy::fromArray(['name' => 'empty']))->pruned);
        self::assertSame(0, $this->pruner()->prune(RetentionPolicy::fromArray(['name' => 'empty'])));
        self::assertCount(6, $this->survivors());
    }

    #[Test]
    public function the_volume_report_counts_the_same_entries(): void
    {
        $report = $this->analyzer()->analyze([
            self::policy(['older_than_days' => 7], 'week'),
            self::policy(['level' => 'debug'], 'debug'),
            RetentionPolicy::fromArray(['name' => 'empty']),
        ], days: 14)->toArray();

        self::assertSame(6, $report['totals']['count']);
        // Due now: A, C, D (week) and C, E (debug), C counted once.
        self::assertSame(4, $report['totals']['due_count']);

        $byName = array_column($report['policies'], null, 'name');
        self::assertSame([6, 3], [$byName['week']['matched_count'], $byName['week']['due_count']]);
        self::assertSame([2, 2], [$byName['debug']['matched_count'], $byName['debug']['due_count']]);
        self::assertFalse($byName['empty']['active']);

        $perDay = array_filter(array_column(array_values($report['buckets']), 'count', 'age_days'));
        ksort($perDay);
        self::assertSame([0 => 1, 1 => 1, 3 => 1, 10 => 3], $perDay);
    }

    /** @param array<string, mixed> $fields */
    private static function policy(array $fields, string $name = 'contract'): RetentionPolicy
    {
        return RetentionPolicy::fromArray(['name' => $name] + $fields);
    }

    /** @return list<string> Keys of the entries still stored, sorted. */
    private function survivors(): array
    {
        $keys = array_map(
            static fn(array $e): string => substr($e['id'], -1),
            $this->storage()->search(SearchFilters::none(), limit: 100)['entries'],
        );
        sort($keys);

        return $keys;
    }
}
