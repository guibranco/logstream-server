<?php

declare(strict_types=1);

namespace LogService\Storage;

/** A log store that can report whether its backend is reachable, for GET /api/health. */
interface ReportsHealth
{
    /**
     * @return array{type: string, reachable: bool, latency_ms: float|null}
     */
    public function health(): array;
}
