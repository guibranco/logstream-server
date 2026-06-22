<?php

declare(strict_types=1);

namespace LogService\Retention;

final class DatabaseRetentionPolicyRepository implements RetentionPolicyRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM retention_policies ORDER BY name');
        return array_map(
            fn(array $row) => $this->rowToPolicy($row),
            $stmt->fetchAll(),
        );
    }

    public function find(string $name): ?RetentionPolicy
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retention_policies WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();
        return $row !== false ? $this->rowToPolicy($row) : null;
    }

    public function create(RetentionPolicy $policy): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO retention_policies
                (name, app_key, app_id, level, category, message_regex, message_glob, older_than_days)
             VALUES
                (:name, :app_key, :app_id, :level, :category, :message_regex, :message_glob, :older_than_days)',
        );
        $stmt->execute($this->policyToParams($policy));
    }

    public function update(RetentionPolicy $policy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE retention_policies
             SET app_key         = :app_key,
                 app_id          = :app_id,
                 level           = :level,
                 category        = :category,
                 message_regex   = :message_regex,
                 message_glob    = :message_glob,
                 older_than_days = :older_than_days,
                 updated_at      = CURRENT_TIMESTAMP
             WHERE name = :name',
        );
        $stmt->execute($this->policyToParams($policy));
    }

    public function delete(string $name): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM retention_policies WHERE name = :name');
        $stmt->execute([':name' => $name]);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function rowToPolicy(array $row): RetentionPolicy
    {
        return RetentionPolicy::fromArray([
            'name'            => $row['name'],
            'app_key'         => $row['app_key'] ?? null,
            'app_id'          => $row['app_id'] ?? null,
            'level'           => $row['level'] ?? null,
            'category'        => $row['category'] ?? null,
            'message_regex'   => $row['message_regex'] ?? null,
            'message_glob'    => $row['message_glob'] ?? null,
            'older_than_days' => isset($row['older_than_days']) ? (int) $row['older_than_days'] : null,
        ]);
    }

    private function policyToParams(RetentionPolicy $policy): array
    {
        return [
            ':name'            => $policy->name,
            ':app_key'         => $policy->appKey,
            ':app_id'          => $policy->appId,
            ':level'           => $policy->level,
            ':category'        => $policy->category,
            ':message_regex'   => $policy->messageRegex,
            ':message_glob'    => $policy->messageGlob,
            ':older_than_days' => $policy->olderThanDays,
        ];
    }
}
