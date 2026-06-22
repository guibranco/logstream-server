<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionRunner
{
    public function __construct(
        private readonly RetentionEngineInterface $engine,
        private readonly RetentionConfig          $config,
    ) {}

    /** @return RetentionResult[] */
    public function runAll(bool $dryRun = false): array
    {
        $results = [];

        foreach ($this->config->getPolicies() as $policy) {
            try {
                $results[] = $this->engine->purge($policy, $dryRun);
            } catch (\Throwable $e) {
                $results[] = new RetentionResult(
                    policy:  $policy->name,
                    pruned:  0,
                    error:   $e->getMessage(),
                    dryRun:  $dryRun,
                    summary: 'Error: ' . $e->getMessage(),
                );
            }
        }

        return $results;
    }

    public function runPolicy(string $name, bool $dryRun = false): RetentionResult
    {
        $policy = $this->config->getPolicy($name);

        if ($policy === null) {
            throw new \InvalidArgumentException("Retention policy '{$name}' not found.");
        }

        return $this->engine->purge($policy, $dryRun);
    }

    /** @return RetentionPolicy[] */
    public function listPolicies(): array
    {
        return $this->config->getPolicies();
    }
}
