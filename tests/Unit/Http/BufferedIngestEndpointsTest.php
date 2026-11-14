<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\Ingest\IngestBuffer;
use LogService\Ingest\IngestConfig;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\Tests\Unit\Ingest\FakeBatchWriter;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;

/**
 * POST /api/logs with INGEST_MODE=buffered: 202 by default, 201 once stored
 * with `Prefer: return=representation`, 503 when the buffer is full.
 */
final class BufferedIngestEndpointsTest extends TestCase
{
    private FakeBatchWriter $writer;
    private IngestBuffer $buffer;
    private StreamSelectLoop $loop;

    protected function setUp(): void
    {
        $this->writer = new FakeBatchWriter();
        $this->loop   = new StreamSelectLoop();
        $this->buffer = new IngestBuffer($this->writer, batchSize: 100, capacity: 200, loop: $this->loop);
    }

    private function router(float $waitSeconds = 5.0): Router
    {
        return new Router(
            storage:           $this->createStub(StorageInterface::class),
            hub:               new LogHub(''),
            writeAuth:         new SingleKeyWriteAuth(''),
            uiSecret:          'ui',
            storageType:       'mariadb',
            ingestBuffer:      $this->buffer,
            ingestWaitSeconds: $waitSeconds,
            ingestDbDriver:    IngestConfig::DRIVER_ASYNC,
        );
    }

    private function ingest(array $body, ?string $prefer = null): ServerRequest
    {
        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode($body)));

        return $prefer !== null ? $request->withHeader('Prefer', $prefer) : $request;
    }

    private const BATCH = [
        'app_key' => 'my-app',
        'app_id'  => 'prod',
        'logs'    => [
            ['message' => 'one', 'trace_id' => 'trace-1'],
            ['message' => 'two'],
        ],
    ];

    // ── Default: 202 ──────────────────────────────────────────────────────────

    #[Test]
    public function it_answers_202_with_the_ids_before_anything_is_written(): void
    {
        $response = $this->router()->handle($this->ingest(self::BATCH));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(2, $body['accepted']);
        self::assertCount(2, $body['entries']);
        self::assertSame('trace-1', $body['entries'][0]['trace_id']);
        self::assertSame(26, strlen($body['entries'][0]['id']));
        self::assertNull($body['errors']);
        self::assertSame('', $response->getHeaderLine('Preference-Applied'));

        self::assertSame([], $this->writer->stored);
        self::assertSame(2, $this->buffer->pending());
    }

    #[Test]
    public function the_queued_entries_are_written_on_the_next_flush(): void
    {
        $body = json_decode((string) $this->router()->handle($this->ingest(self::BATCH))->getBody(), true);

        $this->buffer->flush();

        self::assertSame(array_column($body['entries'], 'id'), $this->writer->stored);
    }

    #[Test]
    public function return_minimal_is_acknowledged(): void
    {
        $response = $this->router()->handle($this->ingest(self::BATCH, 'return=minimal'));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('return=minimal', $response->getHeaderLine('Preference-Applied'));
    }

    #[Test]
    public function invalid_entries_are_reported_while_valid_ones_are_accepted(): void
    {
        $body = self::BATCH;
        $body['logs'][] = ['message' => ['not', 'a', 'string']]; // TypeError in LogEntry::fromArray

        $response = $this->router()->handle($this->ingest($body));
        $decoded  = json_decode((string) $response->getBody(), true);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(2, $decoded['accepted']);
        self::assertCount(1, $decoded['errors']);
        self::assertStringStartsWith('Entry #2:', $decoded['errors'][0]);
    }

    #[Test]
    public function a_request_with_no_valid_entry_is_400_and_queues_nothing(): void
    {
        $response = $this->router()->handle($this->ingest([
            'app_key' => 'my-app',
            'app_id'  => 'prod',
            'logs'    => [['message' => ['not', 'a', 'string']]],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->buffer->pending());
    }

    // ── Prefer: return=representation ────────────────────────────────────────

    #[Test]
    public function return_representation_answers_201_once_the_entries_are_stored(): void
    {
        $result = $this->router()->handle($this->ingest(self::BATCH, 'return=representation'));
        self::assertInstanceOf(PromiseInterface::class, $result);

        $response = null;
        $result->then(function (ResponseInterface $r) use (&$response) { $response = $r; });
        self::assertNull($response, 'waits for the write');

        $this->buffer->flush();

        self::assertNotNull($response);
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('return=representation', $response->getHeaderLine('Preference-Applied'));
        self::assertSame(2, $body['saved']);
        self::assertSame('one', $body['entries'][0]['message']);
        self::assertSame('prod', $body['entries'][1]['app_id']);
    }

    #[Test]
    public function return_representation_falls_back_to_202_when_the_write_is_slow(): void
    {
        $this->writer->holdWrites = true;

        $response = null;
        $this->router(waitSeconds: 0.01)
            ->handle($this->ingest(self::BATCH, 'respond-async, return=representation; foo=bar'))
            ->then(function (ResponseInterface $r) use (&$response) { $response = $r; });
        $this->buffer->flush();

        // The router waits on the global loop.
        $loop = \React\EventLoop\Loop::get();
        $loop->addTimer(0.2, fn() => $loop->stop());
        $loop->run();

        self::assertNotNull($response);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(2, json_decode((string) $response->getBody(), true)['accepted']);
    }

    #[Test]
    public function return_representation_reports_entries_storage_rejected(): void
    {
        $buffer       = new IngestBuffer($this->writer, batchSize: 100, capacity: 200, loop: $this->loop, isolateAfterFailures: 0);
        $this->buffer = $buffer;

        $response = null;
        $this->router()->handle($this->ingest(self::BATCH, 'return=representation'))
            ->then(function (ResponseInterface $r) use (&$response) { $response = $r; });

        // isolateAfterFailures=0 writes entry by entry straight away.
        $this->writer->poisonIf = static fn(LogEntry $e) => $e->message === 'one';
        $buffer->flush();

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['saved']);
        self::assertSame('two', $body['entries'][0]['message']);
        self::assertSame(['Entry #0: rejected by storage'], $body['errors']);
    }

    // ── Backpressure ──────────────────────────────────────────────────────────

    #[Test]
    public function a_full_buffer_answers_503_with_retry_after(): void
    {
        $this->buffer = new IngestBuffer($this->writer, batchSize: 1, capacity: 1, loop: $this->loop);
        $this->writer->down = true;
        $this->router()->handle($this->ingest(['app_key' => 'a', 'app_id' => 'b', 'message' => 'fills it']));

        $response = $this->router()->handle($this->ingest(['app_key' => 'a', 'app_id' => 'b', 'message' => 'no room']));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('full', json_decode((string) $response->getBody(), true)['error']);
    }

    #[Test]
    public function a_draining_buffer_answers_503(): void
    {
        $this->buffer->drain(0.1);

        $response = $this->router()->handle($this->ingest(self::BATCH));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('shutting down', json_decode((string) $response->getBody(), true)['error']);
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    #[Test]
    public function health_exposes_the_buffer_state(): void
    {
        $this->router()->handle($this->ingest(self::BATCH));

        $ingest = json_decode((string) $this->router()->handle(
            new ServerRequest('GET', 'http://localhost/api/health'),
        )->getBody(), true)['ingest'];

        self::assertSame('buffered', $ingest['mode']);
        self::assertSame('async', $ingest['db_driver']);
        self::assertSame('fake', $ingest['writer']);
        self::assertSame(2, $ingest['queued']);
        self::assertSame(200, $ingest['capacity']);
    }

    #[Test]
    public function info_advertises_async_ingest(): void
    {
        $body = json_decode((string) $this->router()->handle(
            new ServerRequest('GET', 'http://localhost/api/info'),
        )->getBody(), true);

        self::assertTrue($body['features']['async_ingest']);
        self::assertSame(['mode' => 'buffered', 'db_driver' => 'async'], $body['ingest']);
    }

    #[Test]
    public function cors_allows_the_prefer_header_and_exposes_the_response_headers(): void
    {
        $response = $this->router()->handle(new ServerRequest('OPTIONS', 'http://localhost/api/logs'));

        self::assertStringContainsString('Prefer', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertStringContainsString('Preference-Applied', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertStringContainsString('Retry-After', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }
}
