<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Storage\StorageInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Writes batches through a StorageInterface (file storage, or MariaDB over
 * PDO when DB_DRIVER=pdo).
 *
 * The write is blocking: the event loop waits while it runs. Batching keeps
 * that to one INSERT (or one append per day file) per flush.
 */
final class StorageBatchWriter implements BatchWriterInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $name,
    ) {}

    public function write(array $entries): PromiseInterface
    {
        try {
            $this->storage->saveMany($entries);
        } catch (\Throwable $e) {
            return reject($e);
        }
        return resolve(null);
    }

    public function name(): string
    {
        return $this->name;
    }
}
