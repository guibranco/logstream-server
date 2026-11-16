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
 * has gone silent. Once the client sends again (status back to active) the
 * next silence alerts again.
 *
 * Episodes are remembered in memory only, so a client that is still silent
 * when the server restarts is reported once more after the restart.
 */
final class ClientSilenceMonitor
{
    public const DEFAULT_INTERVAL_SECONDS = 30.0;
    public const ALERT_NAME               = 'client-silent';

    /** @var array<string, true> app_keys already alerted for the current silence */
    private array $alerted = [];

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
     * @return list<array<string, mixed>> The alerts sent by this check.
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
            $seen[$client->appKey] = true;
            $health = $client->health($now, $this->newestSeen($client));

            if (!$client->active || !$health->isSilent()) {
                unset($this->alerted[$client->appKey]);
                continue;
            }
            if (isset($this->alerted[$client->appKey])) {
                continue;
            }

            $payload = self::payload($client, $health, $now);
            try {
                $this->notifier->notify($payload);
                $this->alerted[$client->appKey] = true;
                $sent[] = $payload;
                ($this->log)('warning', "Client '{$client->appKey}' is silent ({$payload['silent_for']})", [
                    'app_key'         => $client->appKey,
                    'timeout_minutes' => $health->timeoutMinutes,
                ]);
            } catch (\Throwable $e) {
                // Not marked as alerted: the next check tries again.
                ($this->log)('error', "Silence alert for '{$client->appKey}' failed: " . $e->getMessage(), [
                    'app_key' => $client->appKey,
                ]);
            }
        }

        // Forget deleted clients.
        $this->alerted = array_intersect_key($this->alerted, $seen);

        return $sent;
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
