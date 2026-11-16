<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Udp;

use LogService\Health\ClientActivityTracker;
use LogService\Ingest\EntrySink;
use LogService\Ingest\IngestBuffer;
use LogService\Models\LogEntry;
use LogService\Repository\ClientRepository;
use LogService\Storage\StorageInterface;
use LogService\Support\IpRangeList;
use LogService\Tests\Unit\Ingest\FakeBatchWriter;
use LogService\Udp\UdpLogServer;
use LogService\Udp\UdpPacketValidator;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class UdpLogServerTest extends TestCase
{
    private const KEY   = 'billing-api';
    private const TOKEN = 'lss_billing-token';

    /** @var list<LogEntry> */
    private array $saved = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $logs = [];

    private float $now = 1_000_000.0;

    private bool $storageDown = false;

    private function validator(): UdpPacketValidator
    {
        return new UdpPacketValidator(
            fn(string $key): ?string => $key === self::KEY ? self::TOKEN : null,
            clock: fn(): int => (int) ($this->now * 1000),
        );
    }

    /** A synchronous sink that records what it stores. */
    private function syncSink(?ClientActivityTracker $activity = null): EntrySink
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willReturnCallback(function (LogEntry $entry): void {
            if ($this->storageDown) {
                throw new \RuntimeException('storage down');
            }
            $this->saved[] = $entry;
        });

        return new EntrySink($storage, new LogHub(''), null, $activity);
    }

    private function server(?EntrySink $sink = null, string $allowed = ''): UdpLogServer
    {
        return new UdpLogServer(
            $this->validator(),
            $sink ?? $this->syncSink(),
            IpRangeList::fromString($allowed),
            '127.0.0.1:0',
            function (string $level, string $message, array $context = []): void {
                $this->logs[] = [$level, $message, $context];
            },
            fn(): float => $this->now,
        );
    }

    /** @param array<string, mixed> $log */
    private function packet(array $log = [], string $key = self::KEY, string $token = self::TOKEN): string
    {
        $log  += ['app_id' => 'web-1', 'level' => 'error', 'category' => 'payments', 'message' => 'Charge failed'];
        $ts    = (int) ($this->now * 1000);
        $nonce = bin2hex(random_bytes(8));
        $sig   = hash_hmac('sha256', UdpPacketValidator::canonical($key, $ts, $nonce, $log), $token);

        return json_encode(
            ['v' => 1, 'key' => $key, 'ts' => $ts, 'nonce' => $nonce, 'sig' => $sig, 'log' => $log],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    // ── Entries ───────────────────────────────────────────────────────────────

    #[Test]
    public function valid_packet_is_stored_like_an_http_entry(): void
    {
        $server = $this->server();

        $server->handle($this->packet(['context' => ['invoice_id' => 1234], 'trace_id' => 'trace-1']), '10.0.0.7:51234');

        self::assertCount(1, $this->saved);
        $entry = $this->saved[0];
        self::assertSame(self::KEY, $entry->appKey);
        self::assertSame('web-1', $entry->appId);
        self::assertSame('error', $entry->level);
        self::assertSame('payments', $entry->category);
        self::assertSame('Charge failed', $entry->message);
        self::assertSame(['invoice_id' => 1234], $entry->context);
        self::assertSame('trace-1', $entry->traceId);
        self::assertSame('udp-client', $entry->userAgent);
        self::assertSame('10.0.0.7', $entry->senderIp);
        self::assertSame(['received' => 1, 'accepted' => 1, 'dropped' => 0, 'rejected' => [], 'nonces' => 1], $server->stats());
    }

    #[Test]
    public function app_key_comes_from_the_signed_key_not_the_log(): void
    {
        $this->server()->handle($this->packet(['app_key' => 'someone-else']), '10.0.0.7:1');

        self::assertSame(self::KEY, $this->saved[0]->appKey);
    }

    #[Test]
    public function message_templates_are_resolved(): void
    {
        $this->server()->handle(
            $this->packet(['message' => null, 'message_template' => 'Order {order_id} failed', 'context' => ['order_id' => 42]]),
            '10.0.0.7:1',
        );

        self::assertSame('Order 42 failed', $this->saved[0]->message);
        self::assertSame('Order {order_id} failed', $this->saved[0]->messageTemplate);
    }

    #[Test]
    public function ipv6_sender_is_recorded_without_the_port(): void
    {
        $this->server()->handle($this->packet(), '[2001:db8::1]:51234');

        self::assertSame('2001:db8::1', $this->saved[0]->senderIp);
    }

    #[Test]
    public function missing_app_id_is_rejected(): void
    {
        $server = $this->server();
        $log    = ['level' => 'info', 'message' => 'no app id'];
        $ts     = (int) ($this->now * 1000);
        $nonce  = bin2hex(random_bytes(8));
        $sig    = hash_hmac('sha256', UdpPacketValidator::canonical(self::KEY, $ts, $nonce, $log), self::TOKEN);

        $server->handle(json_encode(['v' => 1, 'key' => self::KEY, 'ts' => $ts, 'nonce' => $nonce, 'sig' => $sig, 'log' => $log]), '10.0.0.7:1');

        self::assertSame([], $this->saved);
        self::assertSame([UdpLogServer::REASON_INVALID_ENTRY => 1], $server->stats()['rejected']);
    }

    // ── Rejections ────────────────────────────────────────────────────────────

    #[Test]
    public function forged_packet_is_dropped_and_counted(): void
    {
        $server = $this->server();

        $server->handle($this->packet([], self::KEY, 'wrong-token'), '10.0.0.7:1');

        self::assertSame([], $this->saved);
        self::assertSame(
            ['received' => 1, 'accepted' => 0, 'dropped' => 0, 'rejected' => [UdpPacketValidator::REASON_BAD_SIGNATURE => 1], 'nonces' => 0],
            $server->stats(),
        );
    }

    #[Test]
    public function sender_outside_the_allow_list_is_dropped_before_validation(): void
    {
        $server = $this->server(allowed: '10.0.0.0/8');

        $server->handle($this->packet(), '192.168.1.5:1');
        $server->handle($this->packet(), '10.1.2.3:1');

        self::assertCount(1, $this->saved);
        self::assertSame('10.1.2.3', $this->saved[0]->senderIp);
        self::assertSame([UdpLogServer::REASON_NOT_ALLOWED => 1], $server->stats()['rejected']);
        self::assertSame(1, $server->stats()['nonces'], 'the blocked packet never reached the validator');
    }

    #[Test]
    public function storage_failure_counts_as_dropped(): void
    {
        $this->storageDown = true;
        $server = $this->server();

        $server->handle($this->packet(), '10.0.0.7:1');

        self::assertSame(1, $server->stats()['dropped']);
        self::assertSame(0, $server->stats()['accepted']);
    }

    // ── Rate-limited logging ──────────────────────────────────────────────────

    #[Test]
    public function rejections_are_logged_at_most_once_per_reason_per_minute(): void
    {
        $server = $this->server();

        $server->handle('garbage', '10.0.0.7:1');       // logged straight away
        $server->handle('garbage', '10.0.0.8:1');       // held
        $server->handle('garbage', '10.0.0.9:1');       // held
        $server->handle($this->packet([], self::KEY, 'bad'), '10.0.0.7:1'); // other reason: logged

        self::assertCount(2, $this->logs);
        self::assertSame('UDP: 1 packet dropped (invalid_json), last from 10.0.0.7', $this->logs[0][1]);
        self::assertSame('bad_signature', $this->logs[1][2]['reason']);

        $this->now += 60;
        $server->handle('garbage', '10.0.0.10:1');

        self::assertCount(3, $this->logs);
        self::assertSame('UDP: 3 packets dropped (invalid_json), last from 10.0.0.10', $this->logs[2][1]);
        self::assertSame('warning', $this->logs[2][0]);
    }

    #[Test]
    public function log_lines_never_contain_the_token_or_signature(): void
    {
        $server = $this->server();
        $packet = $this->packet([], self::KEY, 'wrong-token');

        $server->handle($packet, '10.0.0.7:1');

        $sig = json_decode($packet, true)['sig'];
        foreach ($this->logs as [, $message, $context]) {
            $text = $message . json_encode($context);
            self::assertStringNotContainsString($sig, $text);
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString('wrong-token', $text);
        }
    }

    // ── Pipeline integration ──────────────────────────────────────────────────

    #[Test]
    public function buffered_mode_queues_and_reports_a_full_buffer_as_dropped(): void
    {
        $loop   = new StreamSelectLoop();
        $writer = new FakeBatchWriter();
        $writer->holdWrites = true; // the first entry stays in flight, so the buffer is full
        $buffer = new IngestBuffer(
            writer: $writer, batchSize: 1, capacity: 1, flushIntervalSeconds: 60.0,
            onPersisted: static function (): void {}, loop: $loop,
        );
        $sink   = new EntrySink($this->createStub(StorageInterface::class), new LogHub(''), $buffer);
        $server = $this->server($sink);

        $server->handle($this->packet(), '10.0.0.7:1');
        $server->handle($this->packet(), '10.0.0.7:1');

        self::assertCount(1, $writer->batches);
        self::assertSame(1, $server->stats()['accepted']);
        self::assertSame(1, $server->stats()['dropped']);
    }

    #[Test]
    public function sync_mode_updates_client_last_seen(): void
    {
        $tracker = new ClientActivityTracker($this->createStub(ClientRepository::class));
        $server  = $this->server($this->syncSink($tracker));

        $server->handle($this->packet(), '10.0.0.7:1');

        self::assertNotNull($tracker->lastSeen(self::KEY));
    }

    #[Test]
    public function receives_real_datagrams_on_the_loop(): void
    {
        $loop = new StreamSelectLoop();

        // Find a free UDP port.
        $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        $port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = new UdpLogServer(
            new UdpPacketValidator(fn(string $key): ?string => $key === self::KEY ? self::TOKEN : null),
            $this->syncSink(),
            new IpRangeList(),
            "127.0.0.1:{$port}",
        );
        $this->now = microtime(true);
        $server->start($loop);
        $loop->futureTick(function () use ($port): void {
            $client = stream_socket_client("udp://127.0.0.1:{$port}");
            fwrite($client, $this->packet(['message' => 'over the wire']));
            fclose($client);
        });
        $loop->addTimer(0.2, fn() => $server->stop($loop));
        $loop->run();

        self::assertCount(1, $this->saved);
        self::assertSame('over the wire', $this->saved[0]->message);
        self::assertFalse($server->isListening());
    }
}
