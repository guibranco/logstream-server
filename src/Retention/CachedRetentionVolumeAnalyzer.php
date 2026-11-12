<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Keeps the last report for a short time, so repeated chart loads do not
 * re-read every stored log. The key covers the window and every policy's
 * criteria, so editing a policy is reflected immediately; new entries and
 * retention runs show up once the entry expires.
 */
final class CachedRetentionVolumeAnalyzer implements RetentionVolumeAnalyzerInterface
{
    private ?string                $key       = null;
    private ?RetentionVolumeReport $report    = null;
    private float                  $expiresAt = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    public function __construct(
        private readonly RetentionVolumeAnalyzerInterface $inner,
        private readonly int                              $ttlSeconds = 300,
        ?callable                                         $clock = null,
    ) {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => microtime(true);
    }

    public function analyze(array $policies, int $days): RetentionVolumeReport
    {
        $now = ($this->clock)();
        $key = self::keyFor($policies, $days);

        if ($this->report !== null && $this->key === $key && $now < $this->expiresAt) {
            return $this->report;
        }

        $this->report    = $this->inner->analyze($policies, $days);
        $this->key       = $key;
        $this->expiresAt = $now + $this->ttlSeconds;

        return $this->report;
    }

    /** Drop the cached report, e.g. after a retention run deleted entries. */
    public function invalidate(): void
    {
        $this->report = null;
        $this->key    = null;
    }

    /** @param RetentionPolicy[] $policies */
    private static function keyFor(array $policies, int $days): string
    {
        $parts = array_map(static fn(RetentionPolicy $p): array => [
            $p->name, $p->appKey, $p->appId, $p->level, $p->category,
            $p->messageRegex, $p->messageGlob, $p->olderThanDays,
        ], $policies);

        // The day matters too: cutoffs move at midnight.
        return hash('sha256', serialize([$days, date('Y-m-d'), $parts]));
    }
}
