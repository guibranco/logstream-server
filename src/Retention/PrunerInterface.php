<?php

declare(strict_types=1);

namespace LogService\Retention;

interface PrunerInterface
{
    /**
     * Execute a single retention policy and return the number of entries removed.
     */
    public function prune(RetentionPolicy $policy): int;
}
