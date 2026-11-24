<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Models\LogEntry;
use LogService\Storage\ContextFilter;
use LogService\Storage\SearchFilters;
use LogService\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every log store must share: the same seeded entries, the same
 * filters, the same results in the same order. Each backend provides a fresh,
 * empty storage; differences that are by design (context_search on files
 * matches inside words) live in ContextSearchTestCase's subclasses instead.
 */
abstract class StorageContractTestCase extends TestCase
{
    /** A fresh, empty storage for each test. */
    abstract protected function storage(): StorageInterface;

    /** @var array<string, LogEntry> suffix → entry, as written */
    protected array $seeded = [];

    protected function setUp(): void
    {
        $entries = [
            '01' => [
                'app_key' => 'billing-api', 'app_id' => 'vinhedo5', 'level' => 'error', 'category' => 'Payments.Card',
                'message' => 'Card declined for order 42', 'message_template' => 'Card declined for order {orderId}',
                'context' => ['orderId' => 42, 'user' => ['role' => 'admin'], 'flag' => true, 'note' => null],
                'user_agent' => 'curl/8.5.0', 'sender_ip' => '203.0.113.7', 'transport' => 'udp',
                'trace_id' => 'trace-a', 'batch_id' => 'batch-1', 'timestamp' => self::at(0, '10:00:00.000001'),
            ],
            '02' => [
                'app_key' => 'billing-api', 'app_id' => 'vinhedo6', 'level' => 'warning', 'category' => 'Payments',
                'message' => 'Refund issued', 'context' => ['orderId' => 7, 'user' => ['role' => 'bot']],
                'transport' => 'tcp', 'trace_id' => 'trace-a', 'batch_id' => 'batch-1',
                'timestamp' => self::at(0, '11:00:00'),
            ],
            '03' => [
                'app_key' => 'orders-api', 'app_id' => 'vinhedo5', 'level' => 'info', 'category' => 'general',
                'message' => 'São Paulo shipment created', 'context' => null,
                'trace_id' => 'trace-b', 'timestamp' => self::at(1, '09:00:00'),
            ],
            '04' => [
                'app_key' => 'orders-api', 'app_id' => 'vinhedo5', 'level' => 'critical', 'category' => 'Database',
                'message' => 'Database CARD table locked', 'context' => ['orderId' => '42'],
                'trace_id' => 'trace-c', 'timestamp' => self::at(2, '00:00:00'),
            ],
            '05' => [
                'app_key' => 'users-api', 'app_id' => 'vinhedo6', 'level' => 'debug', 'category' => 'Auth',
                'message' => 'Login ok', 'context' => ['user' => ['role' => 'admin']],
                'trace_id' => 'trace-d', 'timestamp' => self::at(2, '12:30:00.5'),
            ],
        ];

        $this->seeded = [];
        foreach ($entries as $suffix => $fields) {
            $this->seeded[$suffix] = LogEntry::fromArray($fields + [
                'id'         => '01JDXS5G8QW3Z6V9N2M4K7H1' . $suffix,
                'batch_id'   => null,
                'user_agent' => null,
                'created_at' => self::at(3, '00:00:00'),
            ]);
        }

        // Two writes, as the ingest buffer would flush them.
        $this->storage()->saveMany([$this->seeded['01'], $this->seeded['02'], $this->seeded['03']]);
        $this->storage()->saveMany([$this->seeded['04'], $this->seeded['05']]);
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function filters(): iterable
    {
        yield 'no filter, newest first'     => [[], ['05', '04', '03', '02', '01']];
        yield 'app_key'                     => [['app_key' => 'billing-api'], ['02', '01']];
        yield 'app_key list'                => [['app_key' => ['orders-api', 'users-api']], ['05', '04', '03']];
        yield 'app_id'                      => [['app_id' => 'vinhedo6'], ['05', '02']];
        yield 'level list'                  => [['level' => ['error', 'critical']], ['04', '01']];
        yield 'unknown level'               => [['level' => 'fatal'], []];
        yield 'trace_id'                    => [['trace_id' => 'trace-a'], ['02', '01']];
        yield 'batch_id'                    => [['batch_id' => 'batch-1'], ['02', '01']];
        yield 'sender_ip'                   => [['sender_ip' => '203.0.113.7'], ['01']];
        yield 'transport udp'               => [['transport' => 'udp'], ['01']];
        yield 'transport tcp'               => [['transport' => 'tcp'], ['02']];
        yield 'message_template'            => [['message_template' => 'Card declined for order {orderId}'], ['01']];
        yield 'user_agent, any case'        => [['user_agent' => 'CURL'], ['01']];
        yield 'category, any case'          => [['category' => 'payments'], ['02', '01']];
        yield 'search, any case'            => [['search' => 'card'], ['04', '01']];
        yield 'search, accented'            => [['search' => 'são'], ['03']];
        yield 'search, no match'            => [['search' => 'refunded'], []];
        yield 'context number or string'    => [['context' => ContextFilter::fromQuery(['orderId' => '42'], null)], ['04', '01']];
        yield 'context nested'              => [['context' => ContextFilter::fromQuery(['user.role' => 'admin'], null)], ['05', '01']];
        yield 'context boolean'             => [['context' => ContextFilter::fromQuery(['flag' => 'true'], null)], ['01']];
        yield 'context null value'          => [['context' => ContextFilter::fromQuery(['note' => 'null'], null)], ['01']];
        yield 'context_not keeps absent'    => [['context' => ContextFilter::fromQuery(null, ['user.role' => 'admin'])], ['04', '03', '02']];
        yield 'date_from'                   => [['date_from' => self::at(1, '00:00:00', sql: true)], ['05', '04', '03']];
        yield 'date range, both inclusive'  => [['date_from' => self::at(0, '11:00:00', sql: true), 'date_to' => self::at(2, '00:00:00', sql: true)], ['04', '03', '02']];
        yield 'combined'                    => [['app_key' => 'billing-api', 'context' => ContextFilter::fromQuery(['orderId' => '42'], null)], ['01']];
    }

    /** @param list<string> $expected id suffixes, newest first */
    #[Test]
    #[DataProvider('filters')]
    public function filters_return_the_same_entries_in_the_same_order(array $filters, array $expected): void
    {
        $result = $this->storage()->search(SearchFilters::fromArray($filters));

        self::assertSame($expected, self::suffixes($result['entries']));
        self::assertSame(count($expected), $result['total']);
    }

    #[Test]
    public function pages_with_limit_and_offset_and_counts_every_match(): void
    {
        $result = $this->storage()->search(SearchFilters::none(), limit: 2, offset: 1);

        self::assertSame(['04', '03'], self::suffixes($result['entries']));
        self::assertSame(5, $result['total']);
        self::assertSame([2, 1], [$result['limit'], $result['offset']]);
    }

    #[Test]
    public function entries_round_trip_unchanged(): void
    {
        foreach ($this->seeded as $suffix => $entry) {
            $found = $this->storage()->findById($entry->id);

            self::assertNotNull($found, "entry {$suffix}");
            self::assertSame($entry->toArray(), $found->toArray(), "entry {$suffix}");
        }
    }

    #[Test]
    public function search_returns_entries_as_find_by_id_does(): void
    {
        $entries = $this->storage()->search(SearchFilters::fromArray(['app_key' => 'billing-api']))['entries'];

        self::assertSame($this->seeded['02']->toArray(), $entries[0]);
        self::assertSame($this->seeded['01']->toArray(), $entries[1]);
    }

    #[Test]
    public function find_by_id_also_finds_a_trace_id(): void
    {
        $found = $this->storage()->findById('trace-a');

        self::assertNotNull($found);
        self::assertContains(substr($found->id, -2), ['01', '02']);
        self::assertSame('trace-b', $this->storage()->findById('trace-b')?->traceId);
        self::assertNull($this->storage()->findById('01JDXS5G8QW3Z6V9N2M4K7H1ZZ'));
    }

    #[Test]
    public function save_stores_a_single_entry(): void
    {
        $entry = LogEntry::fromArray([
            'id' => '01JDXS5G8QW3Z6V9N2M4K7H106', 'trace_id' => 'trace-e', 'app_key' => 'billing-api',
            'app_id' => 'vinhedo5', 'level' => 'notice', 'message' => 'Single entry', 'batch_id' => null, 'user_agent' => null,
            'timestamp' => self::at(3, '08:00:00'),
        ]);

        $this->storage()->save($entry);

        self::assertSame($entry->toArray(), $this->storage()->findById($entry->id)?->toArray());
        self::assertSame(6, $this->storage()->search(SearchFilters::none())['total']);
    }

    /**
     * A moment $day days after the first seeded day, which is four days ago:
     * recent enough for ClickHouse's 35-day TTL, never in the future.
     *
     * @param bool $sql "Y-m-d H:i:s" (as filters send it) instead of ISO 8601 with Z.
     */
    protected static function at(int $day, string $time, bool $sql = false): string
    {
        $date = (new \DateTimeImmutable('today -4 days', new \DateTimeZone('UTC')))
            ->modify("+{$day} days")
            ->format('Y-m-d');

        return $sql ? "{$date} {$time}" : "{$date}T{$time}Z";
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private static function suffixes(array $entries): array
    {
        return array_map(static fn(array $e): string => substr($e['id'], -2), $entries);
    }
}
