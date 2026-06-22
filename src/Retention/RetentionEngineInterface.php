<?php

declare(strict_types=1);

namespace LogService\Retention;

interface RetentionEngineInterface
{
    public function purge(RetentionPolicy $policy, bool $dryRun = false): RetentionResult;
}
