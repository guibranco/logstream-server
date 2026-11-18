<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * One recorded retention run of one policy, as read back from the retention log.
 */
final class RetentionLogEntry
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly int     $id,
        public readonly string  $policy,
        public readonly int     $deleted,
        public readonly bool    $dryRun,
        public readonly int     $durationMs,
        public readonly ?string $summary,
        public readonly array   $warnings,
        public readonly ?string $error,
        /** UTC, `Y-m-d H:i:s`. */
        public readonly string  $ranAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'policy'      => $this->policy,
            'deleted'     => $this->deleted,
            'dry_run'     => $this->dryRun,
            'duration_ms' => $this->durationMs,
            'summary'     => $this->summary,
            'warnings'    => $this->warnings,
            'error'       => $this->error,
            'ran_at'      => (new \DateTimeImmutable($this->ranAt, new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::RFC3339),
        ];
    }

    /** Decodes a stored warnings value (JSON array or null) into a list of strings. */
    public static function decodeWarnings(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
