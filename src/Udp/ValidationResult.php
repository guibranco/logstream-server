<?php

declare(strict_types=1);

namespace LogService\Udp;

/**
 * Result of validating a UDP packet: the decoded packet, or why it was rejected.
 */
final class ValidationResult
{
    private function __construct(
        public readonly bool    $ok,
        /** @var array<string, mixed>|null */
        public readonly ?array  $data,
        /** One of the UdpPacketValidator::REASON_* codes. */
        public readonly ?string $reason,
        /** Human-readable detail, for logs. */
        public readonly ?string $detail = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self(true, $data, null);
    }

    public static function fail(string $reason, ?string $detail = null): self
    {
        return new self(false, null, $reason, $detail);
    }
}
