<?php

declare(strict_types=1);

namespace LogService\Models;

/**
 * Immutable value object representing a single log entry.
 */
final class LogEntry
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
    public const MAX_CATEGORY_LENGTH = 100;
    public const MAX_APP_KEY_LENGTH  = 100;

    public function __construct(
        /** Internal time-sortable ID (ULID-like) */
        public readonly string $id,
        /** Client-supplied trace identifier (UUID) */
        public readonly string $traceId,
        /** Optional batch/request group identifier */
        public readonly ?string $batchId,
        /** Logical application name/slug */
        public readonly string $appKey,
        /** Deployment environment or instance identifier */
        public readonly string $appId,
        /** Free-form string – application name + version sent via User-Agent */
        public readonly ?string $userAgent,
        /** Severity level */
        public readonly string $level,
        /** Short tag for grouping (max 100 chars) */
        public readonly string $category,
        /** Human-readable log message */
        public readonly string $message,
        /** Arbitrary JSON context */
        public readonly ?array $context,
        /** When the event actually occurred */
        public readonly \DateTimeImmutable $timestamp,
        /** When the server persisted the entry */
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────

    public static function fromArray(array $d): self
    {
        return new self(
            id:        $d['id'],
            traceId:   $d['trace_id'],
            batchId:   $d['batch_id'] ?: null,
            appKey:    $d['app_key'],
            appId:     $d['app_id'],
            userAgent: $d['user_agent'] ?: null,
            level:     in_array($d['level'], self::LEVELS, true) ? $d['level'] : 'info',
            category:  substr($d['category'] ?? 'general', 0, self::MAX_CATEGORY_LENGTH),
            message:   $d['message'],
            context:   self::decodeContext($d['context'] ?? null),
            timestamp: self::parseDate($d['timestamp'] ?? 'now'),
            createdAt: self::parseDate($d['created_at'] ?? 'now'),
        );
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'trace_id'   => $this->traceId,
            'batch_id'   => $this->batchId,
            'app_key'    => $this->appKey,
            'app_id'     => $this->appId,
            'user_agent' => $this->userAgent,
            'level'      => $this->level,
            'category'   => $this->category,
            'message'    => $this->message,
            'context'    => $this->context,
            'timestamp'  => $this->timestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            'created_at' => $this->createdAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private static function decodeContext(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    private static function parseDate(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }
}
