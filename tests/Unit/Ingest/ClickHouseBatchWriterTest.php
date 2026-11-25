<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\ClickHouseBatchWriter;
use LogService\Models\LogEntry;
use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouse\ClickHouseSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

/**
 * Against a real HTTP server on a random local port, in the same event loop:
 * what the writer sends, and how it reports failures.
 */
final class ClickHouseBatchWriterTest extends TestCase
{
    private LoopInterface $loop;
    private ?SocketServer $socket = null;

    /** @var list<ServerRequestInterface> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->loop = Loop::get();
    }

    protected function tearDown(): void
    {
        $this->socket?->close();
    }

    /**
     * Start a server answering every request with $respond; returns its URL.
     *
     * @param \Closure(ServerRequestInterface): mixed $respond
     */
    private function server(\Closure $respond): string
    {
        $http = new HttpServer($this->loop, function (ServerRequestInterface $request) use ($respond) {
            $this->received[] = $request;

            return $respond($request);
        });
        $this->socket = new SocketServer('127.0.0.1:0', [], $this->loop);
        $http->listen($this->socket);

        return str_replace('tcp://', 'http://', (string) $this->socket->getAddress());
    }

    private function writer(string $url, float $timeout = 5.0): ClickHouseBatchWriter
    {
        return new ClickHouseBatchWriter(
            new ClickHouseClient(new ClickHouseSettings(url: $url, user: 'logstream', password: 'test-only-password', timeout: $timeout)),
            new Browser(null, $this->loop),
        );
    }

    /** Run the loop until the promise settles; returns [fulfilled?, value or reason]. */
    private function settle(PromiseInterface $promise): array
    {
        $result = null;
        $promise->then(
            function ($value) use (&$result) { $result = [true, $value]; $this->loop->stop(); },
            function ($reason) use (&$result) { $result = [false, $reason]; $this->loop->stop(); },
        );
        $guard = $this->loop->addTimer(10, fn() => $this->loop->stop());
        if ($result === null) {
            $this->loop->run();
        }
        $this->loop->cancelTimer($guard);

        self::assertNotNull($result, 'the promise never settled');

        return $result;
    }

    private static function entry(string $suffix): LogEntry
    {
        return LogEntry::fromArray([
            'id' => '01JDXS5G8QW3Z6V9N2M4K7H1' . $suffix, 'trace_id' => 'trace-' . $suffix, 'batch_id' => 'batch-1',
            'app_key' => 'billing-api', 'app_id' => 'vinhedo5', 'user_agent' => null, 'level' => 'error',
            'message' => 'Card declined', 'context' => ['city' => 'São Paulo'],
            'timestamp' => '2026-11-24T10:00:00.000001Z',
        ]);
    }

    #[Test]
    public function writes_the_batch_as_one_async_insert(): void
    {
        $url    = $this->server(static fn() => new Response(200, [], ''));
        $result = $this->settle($this->writer($url)->write([self::entry('PA'), self::entry('PB')]));

        self::assertSame([true, null], $result);
        self::assertCount(1, $this->received);

        $request = $this->received[0];
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('POST', $request->getMethod());
        self::assertStringStartsWith('INSERT INTO log_entries (id, trace_id, batch_id,', $query['query']);
        self::assertSame(['logstream', '1', '1'], [$query['database'], $query['async_insert'], $query['wait_for_async_insert']]);
        self::assertSame('logstream', $request->getHeaderLine('X-ClickHouse-User'));
        self::assertSame('test-only-password', $request->getHeaderLine('X-ClickHouse-Key'));

        $lines = explode("\n", trim((string) $request->getBody()));
        self::assertCount(2, $lines);
        self::assertSame('01JDXS5G8QW3Z6V9N2M4K7H1PA', json_decode($lines[0], true)['id']);
        self::assertStringContainsString('São Paulo', $lines[0], 'context sent without \\u escapes');
    }

    #[Test]
    public function an_empty_batch_sends_nothing(): void
    {
        $url = $this->server(static fn() => new Response(200));

        self::assertSame([true, null], $this->settle($this->writer($url)->write([])));
        self::assertSame([], $this->received);
    }

    #[Test]
    public function an_error_response_rejects_with_clickhouse_s_message(): void
    {
        $url = $this->server(static fn() => new Response(
            500,
            [],
            "Code: 241. DB::Exception: Memory limit (total) exceeded. (MEMORY_LIMIT_EXCEEDED) (version 26.8.30.8 (official build))\nStack trace: …",
        ));

        [$fulfilled, $reason] = $this->settle($this->writer($url)->write([self::entry('PA')]));

        self::assertFalse($fulfilled);
        self::assertInstanceOf(ClickHouseException::class, $reason);
        self::assertSame(
            'ClickHouse error (HTTP 500): Code: 241. DB::Exception: Memory limit (total) exceeded. (MEMORY_LIMIT_EXCEEDED)',
            $reason->getMessage(),
        );
    }

    #[Test]
    public function an_unreachable_server_rejects(): void
    {
        // Bind a port, then close it: nothing listens there any more.
        $url = $this->server(static fn() => new Response(200));
        $this->socket->close();
        $this->socket = null;

        [$fulfilled, $reason] = $this->settle($this->writer($url)->write([self::entry('PA')]));

        self::assertFalse($fulfilled);
        self::assertInstanceOf(ClickHouseException::class, $reason);
        self::assertStringStartsWith("Cannot write to ClickHouse at {$url}: ", $reason->getMessage());
    }

    #[Test]
    public function a_slow_server_rejects_after_the_timeout(): void
    {
        // Never answers: the promise is left pending.
        $url = $this->server(static fn() => new \React\Promise\Promise(static function () {}));

        $started              = microtime(true);
        [$fulfilled, $reason] = $this->settle($this->writer($url, timeout: 0.3)->write([self::entry('PA')]));

        self::assertFalse($fulfilled);
        self::assertInstanceOf(ClickHouseException::class, $reason);
        self::assertStringContainsString('timed out', $reason->getMessage());
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    #[Test]
    public function the_event_loop_keeps_running_while_a_batch_is_in_flight(): void
    {
        // The server answers after 0.2s; a 0.05s timer must still fire meanwhile.
        $url = $this->server(fn() => new \React\Promise\Promise(function ($resolve) {
            $this->loop->addTimer(0.2, static fn() => $resolve(new Response(200)));
        }));

        $ticks = 0;
        $timer = $this->loop->addPeriodicTimer(0.05, static function () use (&$ticks) { $ticks++; });
        $this->settle($this->writer($url)->write([self::entry('PA')]));
        $this->loop->cancelTimer($timer);

        self::assertGreaterThanOrEqual(2, $ticks, 'the flush must not block the loop');
    }

    #[Test]
    public function is_named_for_health_and_info(): void
    {
        self::assertSame('clickhouse-async', $this->writer('http://127.0.0.1:8123')->name());
    }
}
