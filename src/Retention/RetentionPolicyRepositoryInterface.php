<?php

declare(strict_types=1);

namespace LogService\Retention;

interface RetentionPolicyRepositoryInterface
{
    /** @return RetentionPolicy[] */
    public function all(): array;

    public function find(string $name): ?RetentionPolicy;

    public function create(RetentionPolicy $policy): void;

    public function update(RetentionPolicy $policy): void;

    public function delete(string $name): void;
}
