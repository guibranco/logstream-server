<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Records the outcome of every retention run (real or dry-run), so there is
 * an audit trail of what each policy deleted, how long it took and why it failed.
 */
interface RetentionLogRepositoryInterface
{
    public function record(RetentionResult $result): void;

    /**
     * Runs recorded at or after $since (UTC), newest first.
     *
     * @param string|null $policy Only runs of this policy, when given.
     * @return RetentionLogEntry[]
     */
    public function recent(\DateTimeImmutable $since, ?string $policy = null, int $limit = 500): array;
}
