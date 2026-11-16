<?php

declare(strict_types=1);

namespace LogService\Repository;

use LogService\Health\ClientHealth;

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
        /** When entries were last accepted from this client (UTC), or null if never. */
        public readonly ?\DateTimeImmutable $lastSeenAt = null,
        /** Silence threshold in minutes; null = health monitoring off. */
        public readonly ?int $heartbeatTimeoutMin = null,
    ) {
        $this->rawToken = $rawToken;
    }

    /**
     * Health at $now. $lastSeen overrides the stored value when given — the
     * server passes the newest of the stored and the not-yet-written times.
     */
    public function health(\DateTimeImmutable $now, ?\DateTimeImmutable $lastSeen = null): ClientHealth
    {
        return ClientHealth::evaluate($lastSeen ?? $this->lastSeenAt, $this->heartbeatTimeoutMin, $now);
    }

    /** Copy with a different last-seen time. */
    public function withLastSeenAt(?\DateTimeImmutable $lastSeenAt): self
    {
        return new self(
            id:                  $this->id,
            name:                $this->name,
            appKey:              $this->appKey,
            active:              $this->active,
            createdAt:           $this->createdAt,
            updatedAt:           $this->updatedAt,
            rawToken:            $this->rawToken,
            lastSeenAt:          $lastSeenAt,
            heartbeatTimeoutMin: $this->heartbeatTimeoutMin,
        );
    }

    /**
     * Safe public representation — no token, no internal id.
     * Used by list and get endpoints.
     *
     * `status` is the health status (active | silent | never_seen) at $now;
     * `active` stays the enabled/disabled flag.
     */
    public function toPublicArray(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'name'                  => $this->name,
            'app_key'               => $this->appKey,
            'active'                => $this->active,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
            'last_seen_at'          => ClientHealth::format($this->lastSeenAt),
            'heartbeat_timeout_min' => $this->heartbeatTimeoutMin,
            'status'                => $this->health($now)->status,
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
