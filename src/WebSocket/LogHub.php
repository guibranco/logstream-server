<?php

declare(strict_types=1);

namespace LogService\WebSocket;

use LogService\Models\LogEntry;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * WebSocket hub.
 *
 * Authentication:
 *   Clients must supply the UI secret as a query-string parameter on connect:
 *     ws://host:8080?token=<UI_SECRET>
 *   Connections that fail auth receive a 4401 close frame and are dropped.
 *
 * Outbound message types:
 *   { "type": "connected",   "connections": <int>  }
 *   { "type": "log",         "data": <LogEntry>    }
 *   { "type": "pong"                               }
 *   { "type": "stats",       "data": { connections: <int> } }
 *   { "type": "error",       "message": <string>   }
 *
 * Inbound message types (from UI):
 *   { "type": "ping"  }
 *   { "type": "stats" }
 */
final class LogHub implements MessageComponentInterface
{
    private \SplObjectStorage $clients;

    public function __construct(private readonly string $uiSecret) 
    {
        $this->clients = new \SplObjectStorage();
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function onOpen(ConnectionInterface $conn): void
    {
        // Extract token from query string
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $params);
        $token = $params['token'] ?? '';

        if (!$this->isValidToken($token)) {
            $conn->send($this->encode([
                'type'    => 'error',
                'message' => 'Unauthorized: invalid or missing token.',
            ]));
            $conn->close();
            echo sprintf("[WS] Rejected unauthenticated connection from %s\n", $conn->remoteAddress);
            return;
        }

        $this->clients->attach($conn);

        $conn->send($this->encode([
            'type'        => 'connected',
            'connections' => $this->clients->count(),
        ]));

        echo sprintf("[WS] Client #%s connected  (total: %d)\n", $conn->resourceId, $this->clients->count());
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Ignore messages from unauthenticated connections that slipped through
        if (!$this->clients->contains($from)) {
            $from->close();
            return;
        }

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

    /** Broadcast a freshly-ingested log entry to all authenticated UI clients. */
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

    private function isValidToken(string $token): bool
    {
        if (empty($this->uiSecret)) {
            return true; // Auth disabled — dev mode
        }

        return hash_equals($this->uiSecret, $token);
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
