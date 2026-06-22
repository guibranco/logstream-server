<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly string  $policy,
        public readonly int     $pruned,
        public readonly ?string $error          = null,
        public readonly int     $filesRemoved   = 0,
        public readonly int     $filesRewritten = 0,
        public readonly int     $filesScanned   = 0,
        public readonly bool    $dryRun         = false,
        public readonly int     $durationMs     = 0,
        public readonly string  $summary        = '',
        public readonly array   $warnings       = [],
        public readonly ?string $cutoffDate     = null,
    ) {}

    public function toArray(): array
    {
        $out = [
            'policy'          => $this->policy,
            'pruned'          => $this->pruned,
            'files_removed'   => $this->filesRemoved,
            'files_rewritten' => $this->filesRewritten,
            'files_scanned'   => $this->filesScanned,
            'dry_run'         => $this->dryRun,
            'duration_ms'     => $this->durationMs,
            'summary'         => $this->summary !== ''
                ? $this->summary
                : "{$this->pruned} entries pruned",
        ];

        if ($this->cutoffDate !== null) {
            $out['cutoff_date'] = $this->cutoffDate;
        }

        if (!empty($this->warnings)) {
            $out['warnings'] = $this->warnings;
        }

        if ($this->error !== null) {
            $out['error'] = $this->error;
        }

        return $out;
    }
}
