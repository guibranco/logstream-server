<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Storage\MariaDBStorage;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Writes batches to MariaDB over a non-blocking ReactPHP connection, so a
 * flush never stalls the event loop (HTTP and WebSocket keep being served
 * while the INSERT runs).
 *
 * Uses the same SQL and row mapping as MariaDBStorage, so rows are identical
 * whichever driver wrote them. The lazy connection opens on first use, is
 * kept open while flushes keep coming, and reconnects after the server drops it.
 */
final class AsyncMysqlBatchWriter implements BatchWriterInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly float $timeoutSeconds = 10.0,
        private readonly ?LoopInterface $loop = null,
    ) {}

    /**
     * @param float $idleSeconds Keep the connection open this long between flushes.
     */
    public static function connect(
        string $host,
        int $port,
        string $database,
        string $user,
        #[\SensitiveParameter] string $password,
        float $timeoutSeconds = 10.0,
        float $idleSeconds = 60.0,
    ): self {
        $uri = sprintf(
            '%s:%s@%s:%d/%s?charset=utf8mb4&idle=%s&timeout=%s',
            rawurlencode($user),
            rawurlencode($password),
            str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host,
            $port,
            rawurlencode($database),
            $idleSeconds,
            $timeoutSeconds,
        );

        // react/mysql 0.6 declares implicitly nullable parameters, which PHP
        // 8.4+ reports as E_DEPRECATED when the class is compiled. Load it
        // with deprecations muted so the notice doesn't pollute the logs.
        $level = error_reporting(error_reporting() & ~E_DEPRECATED);
        try {
            class_exists(Factory::class);
        } finally {
            error_reporting($level);
        }

        return new self((new Factory())->createLazyConnection($uri), $timeoutSeconds);
    }

    public function write(array $entries): PromiseInterface
    {
        $params = [];
        foreach ($entries as $entry) {
            array_push($params, ...MariaDBStorage::row($entry));
        }

        $query = $this->connection->query(MariaDBStorage::insertSql(count($entries)), $params);

        return $this->withTimeout($query)->then(static fn() => null);
    }

    public function name(): string
    {
        return 'async-mysql';
    }

    /** Close the connection once pending queries have finished. */
    public function quit(): void
    {
        $this->connection->quit()->then(null, static fn() => null);
    }

    /**
     * Reject if the query hasn't answered in time. The INSERT may still
     * commit afterwards; the retry is then a no-op on the primary key.
     */
    private function withTimeout(PromiseInterface $promise): PromiseInterface
    {
        $loop     = $this->loop ?? Loop::get();
        $deferred = new Deferred();
        $timer    = $loop->addTimer($this->timeoutSeconds, function () use ($deferred) {
            $deferred->reject(new \RuntimeException(
                "MariaDB did not answer within {$this->timeoutSeconds}s",
            ));
        });

        $promise->then(
            function ($value) use ($deferred, $loop, $timer) {
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $e) use ($deferred, $loop, $timer) {
                $loop->cancelTimer($timer);
                $deferred->reject($e);
            },
        );

        return $deferred->promise();
    }
}
