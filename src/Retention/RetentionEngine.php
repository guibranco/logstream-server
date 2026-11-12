<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionEngine
{
    public function __construct(
        private readonly PrunerInterface $pruner,
        private readonly RetentionConfig $config,
        private readonly ?RetentionLogRepositoryInterface $log = null,
    ) {}

    /**
     * Run all configured policies.
     *
     * @return RetentionResult[]
     */
    public function run(): array
    {
        if (!$this->config->enabled) {
            return [];
        }

        $results = [];

        foreach ($this->config->policies as $policy) {
            $start = hrtime(true);

            try {
                $pruned = $this->pruner->prune($policy);
                $result = new RetentionResult(
                    policy:     $policy->name,
                    pruned:     $pruned,
                    durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
                );
            } catch (\Throwable $e) {
                $result = new RetentionResult(
                    policy:  $policy->name,
                    pruned:  0,
                    error:   $e->getMessage(),
                    summary: 'Error: ' . $e->getMessage(),
                );
            }

            $this->record($result);
            $results[] = $result;
        }

        return $results;
    }

    /** A failed audit write must not abort the remaining policies. */
    private function record(RetentionResult $result): void
    {
        if ($this->log === null) {
            return;
        }

        try {
            $this->log->record($result);
        } catch (\Throwable $e) {
            error_log("[Retention] Could not write retention_log for '{$result->policy}': " . $e->getMessage());
        }
    }
}
