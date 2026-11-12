<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionRunner
{
    public function __construct(
        private readonly RetentionEngineInterface            $engine,
        private readonly RetentionConfig                     $config,
        private readonly ?RetentionPolicyRepositoryInterface $repository = null,
        private readonly ?RetentionLogRepositoryInterface    $log        = null,
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
                $result = $this->engine->purge($policy, $dryRun);
            } catch (\Throwable $e) {
                $result = $this->errorResult($policy->name, $e, $dryRun);
            }

            $this->record($result);
            $results[] = $result;
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

        try {
            $result = $this->engine->purge($policy, $dryRun);
        } catch (\Throwable $e) {
            // Keep the failure in the audit trail, then let the caller report it.
            $this->record($this->errorResult($policy->name, $e, $dryRun));
            throw $e;
        }

        $this->record($result);

        return $result;
    }

    /** @return RetentionPolicy[] */
    public function listPolicies(): array
    {
        return $this->repository !== null
            ? $this->repository->all()
            : $this->config->getPolicies();
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function errorResult(string $policyName, \Throwable $e, bool $dryRun): RetentionResult
    {
        return new RetentionResult(
            policy:  $policyName,
            pruned:  0,
            error:   $e->getMessage(),
            dryRun:  $dryRun,
            summary: 'Error: ' . $e->getMessage(),
        );
    }

    /**
     * Writing the audit row must never turn a successful purge into a failure,
     * so a logging error is reported on stderr and otherwise ignored.
     */
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
