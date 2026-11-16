<?php

declare(strict_types=1);

namespace LogService\Udp;

use LogService\Ingest\EntryFactory;
use LogService\Ingest\EntrySink;
use LogService\Support\IpRangeList;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

/**
 * UDP log ingestion: each datagram is one HMAC-signed packet holding one log
 * entry (see UdpPacketValidator). Valid entries go through the same pipeline
 * as POST /api/logs (EntryFactory → EntrySink), so storage, the WebSocket
 * feed and client last-seen behave identically.
 *
 * The server never replies — no amplification surface, and senders cannot
 * probe which app_keys exist. Outcomes are counted (stats(), shown in
 * /api/health) and rejections are logged at most once per reason per minute.
 */
final class UdpLogServer
{
    public const REASON_NOT_ALLOWED   = 'source_not_allowed';
    public const REASON_INVALID_ENTRY = 'invalid_entry';

    /** Seconds between rejection summaries per reason. */
    private const LOG_INTERVAL_SECONDS = 60;

    private ?Socket $socket = null;

    /** @var list<TimerInterface> */
    private array $timers = [];

    private int $received = 0;
    private int $accepted = 0;
    private int $dropped  = 0;

    /** @var array<string, int> reason => total rejections */
    private array $rejected = [];

    /** @var array<string, array{count: int, last_ip: ?string, detail: ?string}> rejections not logged yet */
    private array $pendingLog = [];

    /** @var array<string, float> reason => when it was last logged */
    private array $lastLogged = [];

    /** @var \Closure(string, string, array<string, mixed>): void */
    private readonly \Closure $log;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (callable(string $level, string $message, array<string, mixed> $context): void)|null $log
     * @param (callable(): float)|null $clock Seconds (tests).
     */
    public function __construct(
        private readonly UdpPacketValidator $validator,
        private readonly EntrySink          $sink,
        private readonly IpRangeList        $allowedSources = new IpRangeList(),
        private readonly string             $address        = '0.0.0.0:8082',
        ?callable $log   = null,
        ?callable $clock = null,
    ) {
        $this->log = $log !== null
            ? \Closure::fromCallable($log)
            : static function (string $level, string $message, array $context = []): void {};
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => microtime(true);
    }

    /**
     * Bind the socket. Resolves once listening; a failure is logged and the
     * rest of the server keeps running.
     *
     * @return PromiseInterface<void>
     */
    public function start(LoopInterface $loop): PromiseInterface
    {
        return (new DatagramFactory($loop))->createServer($this->address)->then(
            function (Socket $socket) use ($loop): void {
                $this->socket = $socket;
                $socket->on('message', function (string $raw, string $peer): void {
                    // Never let one packet take down the event loop (and with it HTTP and WebSocket).
                    try {
                        $this->handle($raw, $peer);
                    } catch (\Throwable $e) {
                        $this->reject('internal_error', IpRangeList::normalise($peer), $e::class . ': ' . $e->getMessage());
                    }
                });
                $socket->on('error', function (\Throwable $e): void {
                    ($this->log)('error', 'UDP socket error: ' . $e->getMessage(), []);
                });
                $this->timers[] = $loop->addPeriodicTimer(5.0, fn() => $this->validator->pruneExpired());
                // Logs what is still held back, still at most once per reason per interval.
                $this->timers[] = $loop->addPeriodicTimer(
                    self::LOG_INTERVAL_SECONDS,
                    fn() => $this->flushRejectionLog(force: false),
                );
                ($this->log)('info', "UDP listening on udp://{$this->address}", ['address' => $this->address]);
            },
            function (\Throwable $e): void {
                ($this->log)('error', "UDP could not listen on udp://{$this->address}: " . $e->getMessage(), [
                    'address' => $this->address,
                ]);
            },
        );
    }

    public function stop(LoopInterface $loop): void
    {
        foreach ($this->timers as $timer) {
            $loop->cancelTimer($timer);
        }
        $this->timers = [];
        $this->socket?->close();
        $this->socket = null;
        $this->flushRejectionLog(force: true);
    }

    public function isListening(): bool
    {
        return $this->socket !== null;
    }

    /**
     * Process one datagram. Public for tests; the socket calls it on 'message'.
     *
     * @param string $peer "ip:port" or "[ipv6]:port"
     */
    public function handle(string $raw, string $peer): void
    {
        $this->received++;
        $ip = IpRangeList::normalise($peer);

        if (!$this->allowedSources->isEmpty() && ($ip === null || !$this->allowedSources->contains($ip))) {
            $this->reject(self::REASON_NOT_ALLOWED, $ip, null);
            return;
        }

        $result = $this->validator->validate($raw);
        if (!$result->ok) {
            $this->reject((string) $result->reason, $ip, $result->detail);
            return;
        }

        $packet = $result->data;
        $log    = $packet['log'];
        $appId  = $log['app_id'] ?? null;

        if (!is_string($appId) || trim($appId) === '') {
            $this->reject(self::REASON_INVALID_ENTRY, $ip, 'log.app_id is required');
            return;
        }

        [$entries, $errors] = EntryFactory::build(
            rawLogs:        [$log],
            isSingleKey:    false,
            defaultAppKey:  null,
            resolvedAppKey: $packet['key'],
            topAppId:       $appId,
            topUserAgent:   is_string($log['user_agent'] ?? null) ? $log['user_agent'] : 'udp-client',
            topBatchId:     null,
            senderIp:       $ip,
        );

        if ($entries === []) {
            $this->reject(self::REASON_INVALID_ENTRY, $ip, $errors[0] ?? null);
            return;
        }

        if (!$this->sink->accept(array_values($entries))) {
            $this->dropped++;
            $this->reject('not_stored', $ip, 'ingest buffer full, server shutting down, or write failed', count: false);
            return;
        }

        $this->accepted += count($entries);
    }

    /**
     * @return array{received: int, accepted: int, dropped: int, rejected: array<string, int>, nonces: int}
     */
    public function stats(): array
    {
        ksort($this->rejected);

        return [
            'received' => $this->received,
            'accepted' => $this->accepted,
            'dropped'  => $this->dropped,
            'rejected' => $this->rejected,
            'nonces'   => $this->validator->nonceCount(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function reject(string $reason, ?string $ip, ?string $detail, bool $count = true): void
    {
        if ($count) {
            $this->rejected[$reason] = ($this->rejected[$reason] ?? 0) + 1;
        }

        $pending = $this->pendingLog[$reason] ?? ['count' => 0, 'last_ip' => null, 'detail' => null];
        $pending['count']++;
        $pending['last_ip'] = $ip;
        $pending['detail']  = $detail;
        $this->pendingLog[$reason] = $pending;

        $this->flushRejectionLog(force: false);
    }

    /**
     * One warning per reason at most every LOG_INTERVAL_SECONDS, with how many
     * packets it stands for. Never includes tokens or signatures.
     */
    private function flushRejectionLog(bool $force): void
    {
        $now = ($this->clock)();

        foreach ($this->pendingLog as $reason => $pending) {
            $last = $this->lastLogged[$reason] ?? null;
            if (!$force && $last !== null && $now - $last < self::LOG_INTERVAL_SECONDS) {
                continue;
            }

            $what = $pending['count'] === 1 ? '1 packet' : "{$pending['count']} packets";
            $from = $pending['last_ip'] !== null ? ", last from {$pending['last_ip']}" : '';
            ($this->log)('warning', "UDP: {$what} dropped ({$reason}){$from}", [
                'reason'  => $reason,
                'count'   => $pending['count'],
                'last_ip' => $pending['last_ip'],
                'detail'  => $pending['detail'],
            ]);

            $this->lastLogged[$reason] = $now;
            unset($this->pendingLog[$reason]);
        }
    }
}
