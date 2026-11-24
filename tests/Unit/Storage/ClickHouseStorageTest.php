<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Models\LogEntry;
use LogService\Storage\ClickHouse\ClickHouseSettings;
use LogService\Storage\ClickHouseStorage;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\SearchFilters;
use LogService\Tests\Unit\Storage\ClickHouse\FakeClickHouse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Without a server: what ClickHouseStorage sends and how it reads replies. Live checks run in CI. */
final class ClickHouseStorageTest extends TestCase
{
    private static function entry(array $overrides = []): LogEntry
    {
        return LogEntry::fromArray($overrides + [
            'id'         => '01JDXS5G8QW3Z6V9N2M4K7H1PA',
            'trace_id'   => 'trace-1',
            'batch_id'   => null,
            'app_key'    => 'billing-api',
            'app_id'     => 'vinhedo5',
            'user_agent' => null,
            'level'      => 'error',
            'category'   => 'Payments',
            'message'    => 'Card declined',
            'context'    => ['city' => 'São Paulo', 'path' => '/a/b'],
            'timestamp'  => '2026-11-20T10:00:00.123456+02:00',
            'created_at' => '2026-11-20T08:00:01.000000Z',
        ]);
    }

    /** ClickHouse's JSONEachRow reply for an entry, dates in the requested ISO format. */
    private static function reply(LogEntry $entry): array
    {
        $row = ClickHouseStorage::row($entry);
        $row['timestamp']  = str_replace(' ', 'T', $row['timestamp']) . 'Z';
        $row['created_at'] = str_replace(' ', 'T', $row['created_at']) . 'Z';

        return $row;
    }

    #[Test]
    public function rows_match_mariadb_except_for_unescaped_context(): void
    {
        $entry = self::entry();
        $row   = ClickHouseStorage::row($entry);

        self::assertSame(ClickHouseStorage::COLUMNS, array_keys($row));
        self::assertSame('2026-11-20 08:00:00.123456', $row['timestamp'], 'stored in UTC, with microseconds');
        self::assertSame('{"city":"São Paulo","path":"/a/b"}', $row['context']);

        $mariadb = array_combine(MariaDBStorage::INSERT_COLUMNS, MariaDBStorage::row($entry));
        unset($mariadb['context'], $row['context']);
        self::assertSame($mariadb, $row);
    }

    #[Test]
    public function save_many_inserts_all_entries_in_one_request(): void
    {
        $fake    = new FakeClickHouse();
        $storage = new ClickHouseStorage($fake->client());

        $storage->saveMany([self::entry(), self::entry(['id' => '01JDXS5G8QW3Z6V9N2M4K7H1PB'])]);

        self::assertCount(1, $fake->requests);
        self::assertStringStartsWith('INSERT INTO log_entries (id, trace_id,', $fake->requests[0]['query']['query']);
        self::assertSame(2, substr_count($fake->requests[0]['body'], "\n"));
    }

    #[Test]
    public function search_reads_newest_first_and_counts_with_the_same_filter(): void
    {
        $entry = self::entry();
        $fake  = (new FakeClickHouse())->respondRows([self::reply($entry)])->respondRows([['count()' => 7]]);

        $result = (new ClickHouseStorage($fake->client()))->search(
            SearchFilters::fromArray(['app_key' => 'billing-api']),
            limit: 1,
            offset: 3,
        );

        [$data, $count] = $fake->requests;
        self::assertStringContainsString("FROM log_entries FINAL\nWHERE app_key = {p0:String}\nORDER BY timestamp DESC, id DESC LIMIT {limit:UInt32} OFFSET {offset:UInt32}", $data['body']);
        self::assertSame(['billing-api', '1', '3'], [$data['query']['param_p0'], $data['query']['param_limit'], $data['query']['param_offset']]);
        self::assertStringContainsString("SELECT count() FROM log_entries FINAL\nWHERE app_key = {p0:String}", $count['body']);

        self::assertSame(7, $result['total']);
        self::assertSame([1, 3], [$result['limit'], $result['offset']]);
        // Read back in UTC, whatever offset the client sent (MariaDB does the same).
        $expected              = $entry->toArray();
        $expected['timestamp'] = '2026-11-20T08:00:00.123+00:00';
        self::assertSame($expected, $result['entries'][0]);
    }

    #[Test]
    public function search_without_filters_has_no_where_clause(): void
    {
        $fake = (new FakeClickHouse())->respondRows([])->respondRows([['count()' => 0]]);

        $result = (new ClickHouseStorage($fake->client()))->search(SearchFilters::none());

        self::assertStringNotContainsString('WHERE', $fake->requests[0]['body']);
        self::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'entries' => []], $result);
    }

    #[Test]
    public function find_by_id_prefers_the_id_over_a_trace_id(): void
    {
        $entry = self::entry();
        $fake  = (new FakeClickHouse())->respondRows([self::reply($entry)])->respondRows([]);
        $storage = new ClickHouseStorage($fake->client());

        self::assertEquals($entry, $storage->findById('trace-1'));
        self::assertNull($storage->findById('missing'));

        self::assertStringContainsString('WHERE id = {id:String} OR trace_id = {id:String} ORDER BY id = {id:String} DESC, timestamp DESC LIMIT 1', $fake->requests[0]['body']);
        self::assertSame('trace-1', $fake->requests[0]['query']['param_id']);
    }

    #[Test]
    public function health_reports_an_unreachable_server(): void
    {
        $fake    = new FakeClickHouse();
        $storage = new ClickHouseStorage($fake->client(new ClickHouseSettings(url: 'http://127.0.0.1:1', timeout: 1)));

        self::assertSame(['type' => 'clickhouse', 'reachable' => false, 'latency_ms' => null], $storage->health());
    }
}
