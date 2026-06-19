<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionResult
{
    public function __construct(
        public readonly string  $policy,
        public readonly int     $pruned,
        public readonly ?string $error = null,
    ) {}

    public function toArray(): array
    {
        $out = ['policy' => $this->policy, 'pruned' => $this->pruned];
        if ($this->error !== null) {
            $out['error'] = $this->error;
        }
        return $out;
    }
}
