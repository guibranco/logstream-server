<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Storage\ContextFilter;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\SearchFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchFiltersTest extends TestCase
{
    private const ENTRY = [
        'id'               => '01AAA',
        'trace_id'         => 'trace-1',
        'batch_id'         => 'batch-1',
        'app_key'          => 'billing-api',
        'app_id'           => 'prod',
        'user_agent'       => 'curl/8.5.0',
        'sender_ip'        => '203.0.113.7',
        'transport'        => 'udp',
        'level'            => 'error',
        'category'         => 'Payments.Card',
        'message'          => 'Card declined for order 42',
        'message_template' => 'Card declined for order {orderId}',
        'context'          => ['order' => ['id' => 42], 'user' => ['role' => 'admin']],
        'timestamp'        => '2026-11-20T10:00:00.000000+00:00',
    ];

    // ── fromArray ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_empty_array_has_no_criteria(): void
    {
        self::assertTrue(SearchFilters::fromArray([])->isEmpty());
        self::assertTrue(SearchFilters::none()->isEmpty());
        self::assertSame([], SearchFilters::none()->toArray());
    }

    #[Test]
    public function empty_values_are_ignored(): void
    {
        $filters = SearchFilters::fromArray([
            'app_key' => '', 'level' => [], 'search' => null, 'date_from' => '', 'context' => [],
        ]);

        self::assertTrue($filters->isEmpty());
    }

    #[Test]
    public function exact_fields_become_lists_of_values_keyed_by_column(): void
    {
        $filters = SearchFilters::fromArray([
            'app_key' => 'billing-api',
            'level'   => ['error', 'critical', 'error', ''],
        ]);

        self::assertSame(['app_key' => ['billing-api'], 'level' => ['error', 'critical']], $filters->exact);
    }

    #[Test]
    public function search_maps_to_the_message_column(): void
    {
        $filters = SearchFilters::fromArray(['search' => 'declined', 'category' => 'pay']);

        self::assertSame(['category' => 'pay', 'message' => 'declined'], $filters->substring);
    }

    #[Test]
    public function to_array_round_trips_the_associative_form(): void
    {
        $context = ContextFilter::fromQuery(['order.id' => '42'], null);
        $input   = [
            'app_key'        => 'billing-api',
            'level'          => ['error', 'critical'],
            'search'         => 'declined',
            'context'        => $context,
            'context_search' => 'admin',
            'date_from'      => '2026-11-20T00:00:00Z',
            'date_to'        => '2026-11-21T00:00:00Z',
        ];

        self::assertEquals($input, SearchFilters::fromArray($input)->toArray());
    }

    #[Test]
    public function unknown_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown search filter: foo');

        SearchFilters::fromArray(['foo' => 'bar']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badValues(): iterable
    {
        yield 'exact as int'         => [['app_key' => 5], 'app_key'];
        yield 'exact list of arrays' => [['level' => [['error']]], 'level'];
        yield 'substring as list'    => [['user_agent' => ['curl']], 'user_agent'];
        yield 'date as list'         => [['date_from' => ['2026-01-01']], 'date_from'];
        yield 'context not a list'   => [['context' => 'order.id=42'], 'context'];
        yield 'context of strings'   => [['context' => ['order.id=42']], 'context'];
    }

    #[Test]
    #[DataProvider('badValues')]
    public function values_of_the_wrong_type_are_rejected(array $filters, string $field): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        SearchFilters::fromArray($filters);
    }

    // ── matches ───────────────────────────────────────────────────────────────

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function matchCases(): iterable
    {
        yield 'no criteria'                 => [[], true];
        yield 'exact hit'                   => [['app_key' => 'billing-api'], true];
        yield 'exact miss'                  => [['app_key' => 'users-api'], false];
        yield 'any of a list'               => [['level' => ['warning', 'error']], true];
        yield 'none of a list'              => [['level' => ['warning', 'info']], false];
        yield 'exact is case-sensitive'     => [['transport' => 'UDP'], false];
        yield 'substring, any case'         => [['category' => 'payments'], true];
        yield 'search on message'           => [['search' => 'ORDER 42'], true];
        yield 'search miss'                 => [['search' => 'refund'], false];
        yield 'user agent substring'        => [['user_agent' => 'curl'], true];
        yield 'context equality'            => [['context' => ContextFilter::fromQuery(['order.id' => '42'], null)], true];
        yield 'context inequality'          => [['context' => ContextFilter::fromQuery(null, ['user.role' => 'admin'])], false];
        yield 'context search'              => [['context_search' => 'ADM'], true];
        yield 'context search miss'         => [['context_search' => 'guest'], false];
        yield 'within date range'           => [['date_from' => '2026-11-20 09:00:00', 'date_to' => '2026-11-20T11:00:00Z'], true];
        yield 'inclusive lower bound'       => [['date_from' => '2026-11-20T10:00:00Z'], true];
        yield 'before date_from'            => [['date_from' => '2026-11-20T10:00:01Z'], false];
        yield 'after date_to'               => [['date_to' => '2026-11-20T09:59:59Z'], false];
        yield 'unparseable bound'           => [['date_from' => 'not a date'], false];
        yield 'every criterion must match'  => [['app_key' => 'billing-api', 'level' => 'info'], false];
    }

    #[Test]
    #[DataProvider('matchCases')]
    public function matches_an_entry_the_way_every_store_filters(array $filters, bool $expected): void
    {
        self::assertSame($expected, SearchFilters::fromArray($filters)->matches(self::ENTRY));
    }

    #[Test]
    public function a_missing_field_only_matches_when_unfiltered(): void
    {
        $entry = self::ENTRY;
        unset($entry['sender_ip'], $entry['category']);

        self::assertFalse(SearchFilters::fromArray(['sender_ip' => '203.0.113.7'])->matches($entry));
        self::assertFalse(SearchFilters::fromArray(['category' => 'pay'])->matches($entry));
        self::assertTrue(SearchFilters::fromArray(['app_id' => 'prod'])->matches($entry));
    }

    // ── MariaDB compilation ───────────────────────────────────────────────────

    #[Test]
    public function mariadb_compiles_one_value_as_equality_and_several_as_in(): void
    {
        [$where, $params] = MariaDBStorage::buildWhere(SearchFilters::fromArray([
            'app_key' => 'billing-api',
            'level'   => ['error', 'critical'],
        ]));

        self::assertSame(['app_key = :app_key', 'level IN (:level_0, :level_1)'], $where);
        self::assertSame([':app_key' => 'billing-api', ':level_0' => 'error', ':level_1' => 'critical'], $params);
    }

    #[Test]
    public function mariadb_compiles_substrings_context_and_dates(): void
    {
        [$where, $params] = MariaDBStorage::buildWhere(SearchFilters::fromArray([
            'search'         => 'declined',
            'context'        => ContextFilter::fromQuery(['order.id' => '42'], ['user.role' => 'bot']),
            'context_search' => 'card "x"',
            'date_from'      => '2026-11-20 00:00:00',
            'date_to'        => '2026-11-21 00:00:00',
        ]));

        self::assertSame([
            'message LIKE :message',
            'JSON_UNQUOTE(JSON_EXTRACT(context, :ctx_path_0)) = :ctx_value_0',
            '(JSON_EXTRACT(context, :ctx_path_1) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(context, :ctx_path_1)) <> :ctx_value_1)',
            'MATCH(context) AGAINST(:context_search IN BOOLEAN MODE)',
            'timestamp >= :date_from',
            'timestamp <= :date_to',
        ], $where);
        self::assertSame('%declined%', $params[':message']);
        self::assertSame('$."order"."id"', $params[':ctx_path_0']);
        self::assertSame('bot', $params[':ctx_value_1']);
        self::assertSame('"card  x"', $params[':context_search']);
    }

    #[Test]
    public function mariadb_compiles_no_criteria_to_no_conditions(): void
    {
        self::assertSame([[], []], MariaDBStorage::buildWhere(SearchFilters::none()));
    }
}
