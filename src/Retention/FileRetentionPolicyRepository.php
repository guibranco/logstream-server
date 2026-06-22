<?php

declare(strict_types=1);

namespace LogService\Retention;

final class FileRetentionPolicyRepository implements RetentionPolicyRepositoryInterface
{
    public function __construct(private readonly string $path) {}

    public function all(): array
    {
        return $this->load();
    }

    public function find(string $name): ?RetentionPolicy
    {
        foreach ($this->load() as $policy) {
            if ($policy->name === $name) {
                return $policy;
            }
        }
        return null;
    }

    public function create(RetentionPolicy $policy): void
    {
        $policies = $this->load();

        foreach ($policies as $existing) {
            if ($existing->name === $policy->name) {
                throw new \RuntimeException("Policy '{$policy->name}' already exists.");
            }
        }

        $policies[] = $policy;
        $this->save($policies);
    }

    public function update(RetentionPolicy $policy): void
    {
        $policies = $this->load();
        $found    = false;

        foreach ($policies as $i => $existing) {
            if ($existing->name === $policy->name) {
                $policies[$i] = $policy;
                $found        = true;
                break;
            }
        }

        if (!$found) {
            throw new \RuntimeException("Policy '{$policy->name}' not found.");
        }

        $this->save($policies);
    }

    public function delete(string $name): void
    {
        $policies = $this->load();
        $filtered = array_values(array_filter($policies, fn(RetentionPolicy $p) => $p->name !== $name));

        if (count($filtered) === count($policies)) {
            throw new \RuntimeException("Policy '{$name}' not found.");
        }

        $this->save($filtered);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @return RetentionPolicy[] */
    private function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        if (!\is_array($data)) {
            return [];
        }

        $policies = $data['policies'] ?? $data;
        if (!\is_array($policies)) {
            return [];
        }

        return array_values(array_map(
            static fn(array $p) => RetentionPolicy::fromArray($p),
            array_filter($policies, '\is_array'),
        ));
    }

    /** @param RetentionPolicy[] $policies */
    private function save(array $policies): void
    {
        $data = json_encode(
            [
                'enabled'        => true,
                'interval_hours' => 24,
                'policies'       => array_map(fn(RetentionPolicy $p) => $this->policyToArray($p), $policies),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $tmp = $this->path . '.tmp';
        file_put_contents($tmp, $data, LOCK_EX);
        rename($tmp, $this->path);
    }

    private function policyToArray(RetentionPolicy $policy): array
    {
        return array_filter([
            'name'            => $policy->name,
            'app_key'         => $policy->appKey,
            'app_id'          => $policy->appId,
            'level'           => $policy->level,
            'category'        => $policy->category,
            'message_regex'   => $policy->messageRegex,
            'message_glob'    => $policy->messageGlob,
            'older_than_days' => $policy->olderThanDays,
        ], fn($v) => $v !== null);
    }
}
