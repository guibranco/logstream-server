<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Health\ClientActivityTracker;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;

/**
 * Where built entries go, whatever transport they came from (HTTP or UDP).
 *
 *   Buffered (an IngestBuffer is set): queue them; the buffer writes them in
 *   batches, then broadcasts them and records last-seen (its onPersisted
 *   callback, wired in bin/server.php).
 *   Sync (no buffer): write each one now, broadcast it, record last-seen.
 */
final class EntrySink
{
    public function __construct(
        private readonly StorageInterface       $storage,
        private readonly LogHub                 $hub,
        private readonly ?IngestBuffer          $buffer   = null,
        private readonly ?ClientActivityTracker $activity = null,
    ) {}

    public function buffer(): ?IngestBuffer
    {
        return $this->buffer;
    }

    /**
     * Queue for a batched write. Null when there is no buffer, it is full, or
     * the server is shutting down.
     *
     * @param list<LogEntry> $entries
     */
    public function enqueue(array $entries): ?IngestTicket
    {
        return $this->buffer?->enqueue($entries);
    }

    /**
     * Write each entry now, broadcast it and record last-seen.
     *
     * @param array<int, LogEntry> $entries Keyed by position in the request.
     * @return array{0: list<array<string, mixed>>, 1: list<string>} Saved entries (as arrays) and errors.
     */
    public function saveNow(array $entries): array
    {
        $saved  = [];
        $errors = [];

        foreach ($entries as $i => $entry) {
            try {
                $this->storage->save($entry);
                $this->hub->broadcast($entry);
                $saved[] = $entry->toArray();
            } catch (\Throwable $e) {
                $errors[] = "Entry #{$i}: " . $e->getMessage();
            }
        }

        if ($saved !== []) {
            $this->activity?->touch(...array_column($saved, 'app_key'));
        }

        return [$saved, $errors];
    }

    /**
     * Fire-and-forget: queue when buffered, otherwise write now. For
     * transports that cannot answer (UDP).
     *
     * @param list<LogEntry> $entries
     * @return bool False when nothing was accepted (buffer full / shutting
     *              down, or every synchronous write failed).
     */
    public function accept(array $entries): bool
    {
        if ($entries === []) {
            return false;
        }
        if ($this->buffer !== null) {
            return $this->buffer->enqueue($entries) !== null;
        }

        [$saved] = $this->saveNow($entries);
        return $saved !== [];
    }
}
