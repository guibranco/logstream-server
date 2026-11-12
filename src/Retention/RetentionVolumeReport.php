<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Stored log volume by age, with the share each retention policy matches.
 *
 * Analyzers feed it per-day totals keyed by 'Y-m-d' (the local date of each
 * entry's timestamp):
 *
 *   count / bytes          — every stored entry of that day
 *   due_count / due_bytes  — entries at least one policy would delete right now
 *   policies[name]         — per policy: matched_* (its filters, at any age)
 *                            and due_* (its filters and its cutoff)
 *
 * Because "older_than_days: N" cuts at today−N 00:00, a whole day is either
 * past a policy's cutoff or not, so markers sit exactly on day boundaries:
 * the marker for N lies between age N and age N+1.
 */
final class RetentionVolumeReport
{
    /** Bytes are exact JSONL line sizes (file storage). */
    public const SIZE_BASIS_FILE    = 'file';

    /** Bytes are the length of message, template and context (database storage). */
    public const SIZE_BASIS_PAYLOAD = 'payload';

    private const EMPTY_TOTALS = ['count' => 0, 'bytes' => 0, 'due_count' => 0, 'due_bytes' => 0];

    /**
     * @param array<string, array<string, mixed>> $buckets  One entry per day, oldest first.
     * @param array<string, int>                  $older    Totals for entries older than the window.
     * @param array<string, int>                  $future   Totals for entries timestamped after today.
     * @param array<int, array<string, mixed>>    $policies Per-policy summary.
     * @param array<string, int>                  $totals   Totals over every stored entry.
     */
    private function __construct(
        public readonly \DateTimeImmutable $generatedAt,
        public readonly int                $days,
        public readonly string             $sizeBasis,
        public readonly array              $buckets,
        public readonly array              $older,
        public readonly array              $future,
        public readonly array              $policies,
        public readonly array              $totals,
    ) {}

    /**
     * @param RetentionPolicy[]                   $policies
     * @param array<string, array<string, mixed>> $daily    Per-day totals keyed by 'Y-m-d'.
     */
    public static function fromDailyTotals(
        array               $policies,
        array               $daily,
        int                 $days,
        string              $sizeBasis,
        ?\DateTimeImmutable $today = null,
    ): self {
        $today ??= new \DateTimeImmutable('today');
        $today   = $today->setTime(0, 0);

        $buckets = [];
        for ($age = $days - 1; $age >= 0; $age--) {
            $date           = $today->modify("-{$age} days")->format('Y-m-d');
            $buckets[$date] = ['date' => $date, 'age_days' => $age] + self::EMPTY_TOTALS;
        }

        $older  = self::EMPTY_TOTALS;
        $future = self::EMPTY_TOTALS;
        $totals = self::EMPTY_TOTALS;

        $perPolicy = [];
        foreach ($policies as $policy) {
            $perPolicy[$policy->name] = [
                'name'            => $policy->name,
                'older_than_days' => $policy->olderThanDays,
                'active'          => !$policy->isEmpty(),
                'matched_count'   => 0,
                'matched_bytes'   => 0,
                'due_count'       => 0,
                'due_bytes'       => 0,
            ];
        }

        foreach ($daily as $date => $row) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if ($day === false) {
                continue;
            }

            $values = self::totalsOf($row);
            $totals = self::add($totals, $values);

            $age = (int) $day->diff($today)->format('%r%a');
            if ($age < 0) {
                $future = self::add($future, $values);
            } elseif ($age >= $days) {
                $older = self::add($older, $values);
            } else {
                $buckets[$day->format('Y-m-d')] = self::add($buckets[$day->format('Y-m-d')], $values);
            }

            foreach ((array) ($row['policies'] ?? []) as $name => $p) {
                if (!isset($perPolicy[$name])) {
                    continue;
                }
                foreach (['matched_count', 'matched_bytes', 'due_count', 'due_bytes'] as $key) {
                    $perPolicy[$name][$key] += (int) ($p[$key] ?? 0);
                }
            }
        }

        foreach ($perPolicy as &$p) {
            $p['matched_share'] = $totals['count'] > 0
                ? round($p['matched_count'] / $totals['count'], 4)
                : 0.0;
        }
        unset($p);

        return new self(
            generatedAt: new \DateTimeImmutable(),
            days:        $days,
            sizeBasis:   $sizeBasis,
            buckets:     array_values($buckets),
            older:       $older,
            future:      $future,
            policies:    array_values($perPolicy),
            totals:      $totals,
        );
    }

    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->format(\DateTimeInterface::RFC3339),
            'days'         => $this->days,
            'size_basis'   => $this->sizeBasis,
            'buckets'      => $this->buckets,
            'older'        => $this->older,
            'future'       => $this->future,
            'policies'     => $this->policies,
            'totals'       => $this->totals,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private static function totalsOf(array $row): array
    {
        $out = [];
        foreach (self::EMPTY_TOTALS as $key => $_) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function add(array $into, array $values): array
    {
        foreach ($values as $key => $value) {
            $into[$key] += $value;
        }
        return $into;
    }
}
