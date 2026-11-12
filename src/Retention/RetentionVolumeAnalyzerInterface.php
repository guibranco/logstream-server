<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Measures stored log volume by age and how much of it each retention policy
 * matches, for a Seq-style storage chart with retention markers.
 */
interface RetentionVolumeAnalyzerInterface
{
    /**
     * @param RetentionPolicy[] $policies
     * @param int               $days     Number of daily buckets, counting back from today.
     */
    public function analyze(array $policies, int $days): RetentionVolumeReport;
}
