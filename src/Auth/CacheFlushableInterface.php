<?php

declare(strict_types=1);

namespace LogService\Auth;

interface CacheFlushableInterface
{
    public function flushCache(): void;
}
