<?php

declare(strict_types=1);

namespace LogService\Health;

use React\Promise\PromiseInterface;

/** Receives an alert when a monitored client goes silent. */
interface SilenceNotifierInterface
{
    /**
     * Deliver the alert. The promise fulfils once it is delivered and rejects
     * when delivery fails, so the monitor can try again on its next check.
     *
     * @param array<string, mixed> $payload See ClientSilenceMonitor::payload().
     * @return PromiseInterface<mixed>
     */
    public function notify(array $payload): PromiseInterface;
}
