<?php

declare(strict_types=1);

namespace LogService\Health;

/** Receives an alert when a monitored client goes silent. */
interface SilenceNotifierInterface
{
    /**
     * @param array<string, mixed> $payload See ClientSilenceMonitor::payload().
     */
    public function notify(array $payload): void;
}
