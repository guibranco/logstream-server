<?php

declare(strict_types=1);

namespace LogService\Repository;

/**
 * Immutable value object representing a registered client application.
 *
 * Security contract:
 *   The `api_token` is NEVER included in toPublicArray().
 *   The raw token is only available immediately after creation or rotation
 *   via the `rawToken` property — it is never stored in plaintext after that
 *   single response and never re-read from the database.
 */
final class Client
{
    /**
     * Holds the raw token only when freshly generated (create or rotate).
     * Null on every subsequent read from the database.
     */
    public readonly ?string $rawToken;

    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly string $appKey,
        public readonly bool   $active,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        ?string $rawToken = null,
    ) {
        $this->rawToken = $rawToken;
    }

    /**
     * Safe public representation — no token, no internal id.
     * Used by list and get endpoints.
     */
    public function toPublicArray(): array
    {
        return [
            'name'       => $this->name,
            'app_key'    => $this->appKey,
            'active'     => $this->active,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Returned exactly once on create or token rotation.
     * Includes the raw token — the caller must store it immediately.
     * It will not be retrievable again.
     */
    public function toCreatedArray(): array
    {
        return array_merge($this->toPublicArray(), [
            'api_token' => $this->rawToken,
            '_note'     => 'Store this token now — it will not be shown again.',
        ]);
    }
}
