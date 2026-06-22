<?php

declare(strict_types=1);

namespace LogService\Repository;

/**
 * CRUD repository for the `clients` table.
 *
 * Token security:
 *   - Tokens are generated with random_bytes(32) → URL-safe base64 (44 chars)
 *   - The raw token is surfaced only from create() and rotateToken()
 *   - All subsequent reads return a Client with rawToken = null
 *   - Deletion cascades to log_entries via a single transaction
 */
class ClientRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    /** @return Client[] All registered clients, ordered by name. */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, app_key, active, created_at, updated_at
             FROM clients
             ORDER BY name ASC'
        );
        return array_map(
            fn(array $row) => $this->hydrate($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** Find by app_key; returns null when not found. */
    public function findByKey(string $appKey): ?Client
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, app_key, active, created_at, updated_at
             FROM clients
             WHERE app_key = :app_key
             LIMIT 1'
        );
        $stmt->execute([':app_key' => $appKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Register a new client application.
     *
     * Generates a cryptographically random api_token and returns a Client
     * whose rawToken holds it. This is the only time the token is surfaced.
     *
     * @throws \RuntimeException on app_key conflict.
     */
    public function create(string $name, string $appKey): Client
    {
        if ($this->findByKey($appKey) !== null) {
            throw new \RuntimeException(
                "An application with app_key '{$appKey}' already exists."
            );
        }

        $token = $this->generateToken();

        $this->pdo->prepare(
            'INSERT INTO clients (name, app_key, api_token)
             VALUES (:name, :app_key, :api_token)'
        )->execute([
            ':name'      => $name,
            ':app_key'   => $appKey,
            ':api_token' => $token,
        ]);

        return new Client(
            id:        (int) $this->pdo->lastInsertId(),
            name:      $name,
            appKey:    $appKey,
            active:    true,
            createdAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            updatedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            rawToken:  $token,
        );
    }

    /**
     * Update the name and/or active status of an existing client.
     *
     * @throws \RuntimeException when the app_key is not found.
     */
    public function update(string $appKey, string $name, bool $active): Client
    {
        if ($this->findByKey($appKey) === null) {
            throw new \RuntimeException("Client '{$appKey}' not found.");
        }

        $this->pdo->prepare(
            'UPDATE clients
             SET name = :name, active = :active
             WHERE app_key = :app_key'
        )->execute([
            ':name'    => $name,
            ':active'  => (int) $active,
            ':app_key' => $appKey,
        ]);

        return $this->findByKey($appKey);
    }

    /**
     * Generate a fresh api_token for the given client.
     *
     * Returns a Client with rawToken set. This is the only other time
     * the token is ever surfaced. The old token stops working immediately
     * (caller must flush the DatabaseWriteAuth cache after this).
     *
     * @throws \RuntimeException when the app_key is not found.
     */
    public function rotateToken(string $appKey): Client
    {
        $existing = $this->findByKey($appKey);
        if ($existing === null) {
            throw new \RuntimeException("Client '{$appKey}' not found.");
        }

        $token = $this->generateToken();

        $this->pdo->prepare(
            'UPDATE clients SET api_token = :api_token WHERE app_key = :app_key'
        )->execute([
            ':api_token' => $token,
            ':app_key'   => $appKey,
        ]);

        $refreshed = $this->findByKey($appKey);
        return new Client(
            id:        $refreshed->id,
            name:      $refreshed->name,
            appKey:    $refreshed->appKey,
            active:    $refreshed->active,
            createdAt: $refreshed->createdAt,
            updatedAt: $refreshed->updatedAt,
            rawToken:  $token,
        );
    }

    /**
     * Delete a client and ALL log entries associated with its app_key.
     *
     * Runs inside a transaction — both deletes succeed or neither does.
     *
     * @throws \RuntimeException when the app_key is not found.
     * @return int Number of log entries that were deleted alongside the client.
     */
    public function delete(string $appKey): int
    {
        if ($this->findByKey($appKey) === null) {
            throw new \RuntimeException("Client '{$appKey}' not found.");
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM log_entries WHERE app_key = :app_key'
            );
            $stmt->execute([':app_key' => $appKey]);
            $logsDeleted = $stmt->rowCount();

            $this->pdo->prepare(
                'DELETE FROM clients WHERE app_key = :app_key'
            )->execute([':app_key' => $appKey]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new \RuntimeException(
                "Failed to delete client '{$appKey}': " . $e->getMessage(),
                previous: $e,
            );
        }

        return $logsDeleted;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function hydrate(array $row): Client
    {
        return new Client(
            id:        (int)   $row['id'],
            name:      (string)$row['name'],
            appKey:    (string)$row['app_key'],
            active:    (bool)  $row['active'],
            createdAt: (string)$row['created_at'],
            updatedAt: (string)$row['updated_at'],
            rawToken:  null,
        );
    }

    /** Generates a URL-safe, 43-character base64 token from 32 random bytes. */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
