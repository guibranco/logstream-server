<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionEngine
{
    public function __construct(
        private readonly PrunerInterface $pruner,
        private readonly RetentionConfig $config,
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
            try {
                $pruned    = $this->pruner->prune($policy);
                $results[] = new RetentionResult($policy->name, $pruned);
            } catch (\Throwable $e) {
                $results[] = new RetentionResult($policy->name, 0, $e->getMessage());
            }
        }

        return $results;
    }
}
