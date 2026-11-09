<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/**
 * Context filtering end to end: HTTP query string → Router → real storage.
 * Each backend runs the same cases by providing a fresh, empty storage.
 */
abstract class ContextSearchTestCase extends TestCase
{
    abstract protected function storage(): StorageInterface;

    protected function setUp(): void
    {
        $storage = $this->storage();

        $storage->save($this->entry('01HZA0000000000000000000AA', 'Charge failed', [
            'code'       => 'card_declined',
            'invoice_id' => 1234,
            'order'      => ['id' => 42],
            'role'       => 'bot',
        ]));
        $storage->save($this->entry('01HZA0000000000000000000BB', 'Charge retried', [
            'code'  => 'ok',
            'msg'   => 'Card expired today',
            'order' => ['id' => 7],
            'role'  => 'human',
        ]));
        $storage->save($this->entry('01HZA0000000000000000000CC', 'No context', null));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function queries(): iterable
    {
        yield 'find number'                 => ['context[invoice_id]=1234', ['AA']];
        yield 'find string'                 => ['context[code]=card_declined', ['AA']];
        yield 'find nested'                 => ['context[order.id]=7', ['BB']];
        yield 'find no match'               => ['context[code]=refunded', []];
        yield 'exclude keeps absent'        => ['context_not[role]=bot', ['BB', 'CC']];
        yield 'find and exclude combined'   => ['context[order.id]=42&context_not[role]=bot', []];
        yield 'search whole word'           => ['context_search=card_declined', ['AA']];
        yield 'search inside text value'    => ['context_search=expired', ['BB']];
        yield 'search phrase'               => ['context_search=' . rawurlencode('expired today'), ['BB']];
        yield 'search number'               => ['context_search=1234', ['AA']];
        yield 'search no match'             => ['context_search=refunded', []];
        yield 'search combined with filter' => ['context_search=human&context[order.id]=7', ['BB']];
    }

    /** @param list<string> $expectedSuffixes */
    #[Test]
    #[DataProvider('queries')]
    public function it_filters_entries_by_context(string $query, array $expectedSuffixes): void
    {
        self::assertSame($expectedSuffixes, $this->searchIds($query));
    }

    #[Test]
    public function it_rejects_a_too_short_search_term(): void
    {
        $response = $this->get('context_search=42');

        self::assertSame(400, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** IDs (last two chars) returned for the query, sorted. */
    protected function searchIds(string $query): array
    {
        $response = $this->get($query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $ids  = array_map(fn(array $e) => substr($e['id'], -2), $body['entries']);
        sort($ids);

        self::assertSame(count($ids), $body['total']);

        return $ids;
    }

    private function get(string $query): \Psr\Http\Message\ResponseInterface
    {
        $router = new Router(
            storage:     $this->storage(),
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth(''),
            uiSecret:    'ui-secret',
            storageType: 'test',
        );

        parse_str($query, $params);
        $request = (new ServerRequest('GET', 'http://localhost/api/logs?' . $query))
            ->withQueryParams($params)
            ->withHeader('Authorization', 'Bearer ui-secret');

        return $router->handle($request);
    }

    private function entry(string $id, string $message, ?array $context): LogEntry
    {
        return LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'batch_id'   => null,
            'app_key'    => 'billing-api',
            'app_id'     => 'test',
            'user_agent' => 'IntegrationTest/1.0',
            'level'      => 'error',
            'category'   => 'payments',
            'message'    => $message,
            'context'    => $context,
            'timestamp'  => '2026-03-01T10:00:00.000Z',
            'created_at' => '2026-03-01T10:00:00.000Z',
        ]);
    }
}
