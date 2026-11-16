<?php

declare(strict_types=1);

namespace LogService\Health;

use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Dead-client detection: every check() loads the clients and sends one alert
 * per silence episode for each enabled client with a heartbeat timeout that
 * has gone silent. An episode is identified by the last-seen time it started
 * from: once the client sends again — even if it falls silent again before
 * the next check — the new silence alerts again.
 *
 * A client counts as alerted only once delivery succeeds; a failed delivery
 * is retried on the next check, and no second alert is sent while one is
 * still in flight.
 *
 * Episodes are remembered in memory only, so a client that is still silent
 * when the server restarts is reported once more after the restart.
 */
final class ClientSilenceMonitor
{
    public const DEFAULT_INTERVAL_SECONDS = 30.0;
    public const ALERT_NAME               = 'client-silent';

    /** @var array<string, ?\DateTimeImmutable> app_key => last-seen time of the silence already alerted */
    private array $alerted = [];

    /** @var array<string, true> app_keys with a delivery in progress */
    private array $inFlight = [];

    private ?TimerInterface $timer = null;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @var \Closure(string, string, array<string, mixed>): void */
    private readonly \Closure $log;

    /**
     * @param (callable(): \DateTimeImmutable)|null                        $clock
     * @param (callable(string, string, array<string, mixed>): void)|null $log
     */
    public function __construct(
        private readonly ClientRepository          $clients,
        private readonly SilenceNotifierInterface  $notifier,
        private readonly ?ClientActivityTracker    $activity = null,
        ?callable $clock = null,
        ?callable $log   = null,
    ) {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->log = $log !== null
            ? \Closure::fromCallable($log)
            : static function (string $level, string $message, array $context = []): void {};
    }

    /**
     * Run one check.
     *
     * @return list<array<string, mixed>> The alerts whose delivery this check started.
     */
    public function check(): array
    {
        $now = ($this->clock)();

        try {
            $clients = $this->clients->all();
        } catch (\Throwable $e) {
            ($this->log)('warning', 'Client health check failed: ' . $e->getMessage(), []);
            return [];
        }

        $sent = [];
        $seen = [];

        foreach ($clients as $client) {
            $key        = $client->appKey;
            $seen[$key] = true;
            $health     = $client->health($now, $this->newestSeen($client));

            if (!$client->active || !$health->isSilent()) {
                unset($this->alerted[$key]);
                continue;
            }
            if (isset($this->inFlight[$key]) || $this->alreadyAlerted($key, $health->lastSeen)) {
                continue;
            }

            $payload = self::payload($client, $health, $now);
            if ($this->send($key, $health->lastSeen, $payload)) {
                $sent[] = $payload;
            }
        }

        // Forget deleted clients.
        $this->alerted = array_intersect_key($this->alerted, $seen);

        return $sent;
    }

    /** Whether this silence (identified by its last-seen time) was already delivered. */
    private function alreadyAlerted(string $key, ?\DateTimeImmutable $lastSeen): bool
    {
        if (!array_key_exists($key, $this->alerted)) {
            return false;
        }
        $alertedFor = $this->alerted[$key];
        // Seen again since that alert: a new silence episode.
        return !($lastSeen !== null && $alertedFor !== null && $lastSeen > $alertedFor);
    }

    /**
     * Starts delivery. Marks the client alerted when it succeeds; a failure
     * leaves it unmarked so the next check retries.
     *
     * @param array<string, mixed> $payload
     * @return bool Whether delivery was started.
     */
    private function send(string $key, ?\DateTimeImmutable $lastSeen, array $payload): bool
    {
        $onFailure = function (\Throwable $e) use ($key): void {
            unset($this->inFlight[$key]);
            ($this->log)('error', "Silence alert for '{$key}' failed: " . $e->getMessage(), ['app_key' => $key]);
        };

        $this->inFlight[$key] = true;
        try {
            $promise = $this->notifier->notify($payload);
        } catch (\Throwable $e) {
            $onFailure($e);
            return false;
        }

        $promise->then(
            function () use ($key, $lastSeen, $payload): void {
                unset($this->inFlight[$key]);
                $this->alerted[$key] = $lastSeen;
                ($this->log)('warning', "Client '{$key}' is silent ({$payload['silent_for']})", [
                    'app_key'         => $key,
                    'timeout_minutes' => $payload['timeout_minutes'],
                ]);
            },
            $onFailure,
        );

        return true;
    }

    /**
     * Webhook body. Follows the alert payload shape of the threshold alerts
     * (alert, triggered, window, app_key) plus the client's health details.
     *
     * @return array<string, mixed>
     */
    public static function payload(Client $client, ClientHealth $health, \DateTimeImmutable $now): array
    {
        return [
            'alert'           => self::ALERT_NAME,
            'triggered'       => ClientHealth::format($now),
            'window'          => "{$health->timeoutMinutes}m",
            'app_key'         => $client->appKey,
            'name'            => $client->name,
            'status'          => $health->status,
            'last_seen'       => ClientHealth::format($health->lastSeen),
            'silent_for'      => $health->lastSeenAgo(),
            'timeout_minutes' => $health->timeoutMinutes,
        ];
    }

    public function start(LoopInterface $loop, float $seconds = self::DEFAULT_INTERVAL_SECONDS): void
    {
        if ($this->timer !== null) {
            return;
        }
        $this->timer = $loop->addPeriodicTimer(max(1.0, $seconds), function (): void {
            $this->check();
        });
    }

    private function newestSeen(Client $client): ?\DateTimeImmutable
    {
        $pending = $this->activity?->lastSeen($client->appKey);
        if ($pending === null) {
            return $client->lastSeenAt;
        }
        return $client->lastSeenAt === null || $pending > $client->lastSeenAt ? $pending : $client->lastSeenAt;
    }
}
