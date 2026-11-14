<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\AsyncMysqlBatchWriter;
use LogService\Models\LogEntry;
use LogService\Storage\MariaDBStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\MySQL\ConnectionInterface;
use React\MySQL\QueryResult;
use React\Promise\Deferred;

use function React\Promise\reject;
use function React\Promise\resolve;

final class AsyncMysqlBatchWriterTest extends TestCase
{
    #[Test]
    public function it_inserts_the_batch_with_one_multi_row_query(): void
    {
        $sql        = null;
        $params     = null;
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('query')
            ->willReturnCallback(function (string $s, array $p) use (&$sql, &$params) {
                [$sql, $params] = [$s, $p];
                return resolve(new QueryResult());
            });

        $entries = [$this->entry('A'), $this->entry('B')];
        $done    = false;
        (new AsyncMysqlBatchWriter($connection))->write($entries)->then(function () use (&$done) { $done = true; });

        self::assertTrue($done);
        self::assertSame(MariaDBStorage::insertSql(2), $sql);
        self::assertSame(
            array_merge(MariaDBStorage::row($entries[0]), MariaDBStorage::row($entries[1])),
            $params,
        );
    }

    #[Test]
    public function it_rejects_when_the_query_fails(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(reject(new \RuntimeException('Connection refused')));

        $error = null;
        (new AsyncMysqlBatchWriter($connection))->write([$this->entry('A')])
            ->then(null, function (\Throwable $e) use (&$error) { $error = $e; });

        self::assertSame('Connection refused', $error?->getMessage());
    }

    #[Test]
    public function it_rejects_when_the_database_does_not_answer_in_time(): void
    {
        $loop       = new StreamSelectLoop();
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn((new Deferred())->promise()); // never answers

        $error = null;
        (new AsyncMysqlBatchWriter($connection, timeoutSeconds: 0.01, loop: $loop))
            ->write([$this->entry('A')])
            ->then(null, function (\Throwable $e) use (&$error, $loop) { $error = $e; $loop->stop(); });
        $loop->addTimer(1, fn() => $loop->stop());
        $loop->run();

        self::assertStringContainsString('did not answer', (string) $error?->getMessage());
    }

    #[Test]
    public function connect_builds_a_lazy_writer_without_touching_the_network(): void
    {
        $writer = AsyncMysqlBatchWriter::connect('127.0.0.1', 3306, 'logservice', 'user@x', 'p:ss/word');

        self::assertSame('async-mysql', $writer->name());
    }

    private function entry(string $id): LogEntry
    {
        return LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => "trace-{$id}",
            'batch_id'   => null,
            'app_key'    => 'app',
            'app_id'     => 'test',
            'user_agent' => null,
            'level'      => 'info',
            'message'    => "Entry {$id}",
            'context'    => ['k' => 'v'],
        ]);
    }
}
