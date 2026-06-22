<?php

declare(strict_types=1);

namespace LogService\Retention;

final class RetentionConfig
{
    /** @param RetentionPolicy[] $policies */
    public function __construct(
        public readonly bool  $enabled       = false,
        public readonly int   $intervalHours = 24,
        public readonly array $policies      = [],
    ) {}

    // ──────────────────────────────────────────────────────────────────────────

    public static function fromFile(string $path): self
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new \RuntimeException("Retention config not found or not readable: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in retention config: {$path}");
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $policies = array_values(array_map(
            static fn(array $p) => RetentionPolicy::fromArray($p),
            array_filter($data['policies'] ?? [], 'is_array'),
        ));

        return new self(
            enabled:       (bool) ($data['enabled']        ?? false),
            intervalHours: (int)  ($data['interval_hours'] ?? 24),
            policies:      $policies,
        );
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return RetentionPolicy[] */
    public function getPolicies(): array
    {
        return $this->policies;
    }

    public function getPolicy(string $name): ?RetentionPolicy
    {
        foreach ($this->policies as $policy) {
            if ($policy->name === $name) {
                return $policy;
            }
        }
        return null;
    }
}
