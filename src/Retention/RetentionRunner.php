<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionRunner
{
    public function __construct(
        private readonly RetentionEngineInterface            $engine,
        private readonly RetentionConfig                     $config,
        private readonly ?RetentionPolicyRepositoryInterface $repository = null,
    ) {}

    /** @return RetentionResult[] */
    public function runAll(bool $dryRun = false): array
    {
        $policies = $this->repository !== null
            ? $this->repository->all()
            : $this->config->getPolicies();

        $results = [];

        foreach ($policies as $policy) {
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
        $policy = $this->repository !== null
            ? $this->repository->find($name)
            : $this->config->getPolicy($name);

        if ($policy === null) {
            throw new \InvalidArgumentException("Retention policy '{$name}' not found.");
        }

        return $this->engine->purge($policy, $dryRun);
    }

    /** @return RetentionPolicy[] */
    public function listPolicies(): array
    {
        return $this->repository !== null
            ? $this->repository->all()
            : $this->config->getPolicies();
    }
}
