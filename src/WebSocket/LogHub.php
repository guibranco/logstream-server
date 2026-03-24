<?php

declare(strict_types=1);

namespace LogService\WebSocket;

use LogService\Models\LogEntry;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * WebSocket hub.
 *
 * Connected UI clients receive every ingested log entry in real-time.
 *
 * Outbound message types:
 *   { "type": "connected",   "connections": <int> }
 *   { "type": "log",         "data": <LogEntry>   }
 *   { "type": "pong"                              }
 *   { "type": "stats",       "data": { connections: <int> } }
 *
 * Inbound message types (from UI):
 *   { "type": "ping"  }
 *   { "type": "stats" }
 */
final class LogHub implements MessageComponentInterface
{
    private \SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);

        $conn->send($this->encode([
            'type'        => 'connected',
            'connections' => $this->clients->count(),
        ]));

        echo sprintf("[WS] Client #%s connected  (total: %d)\n", $conn->resourceId, $this->clients->count());
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);

        match ($data['type'] ?? '') {
            'ping'  => $from->send($this->encode(['type' => 'pong'])),
            'stats' => $from->send($this->encode([
                'type' => 'stats',
                'data' => ['connections' => $this->clients->count()],
            ])),
            default => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        echo sprintf("[WS] Client #%s disconnected (total: %d)\n", $conn->resourceId, $this->clients->count());
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo sprintf("[WS] Error on #%s: %s\n", $conn->resourceId, $e->getMessage());
        $conn->close();
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** Broadcast a freshly-ingested log entry to all connected UI clients. */
    public function broadcast(LogEntry $entry): void
    {
        if ($this->clients->count() === 0) {
            return;
        }

        $payload = $this->encode(['type' => 'log', 'data' => $entry->toArray()]);

        foreach ($this->clients as $client) {
            $client->send($payload);
        }
    }

    public function getConnectionCount(): int
    {
        return $this->clients->count();
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
