<?php

declare(strict_types=1);

namespace LogService\Retention;

/**
 * Reads every {basePath}/YYYY/MM/DD.jsonl file and buckets its entries by the
 * local date of their timestamp (falling back to the file's date), measuring
 * each line's size and which policies it matches.
 *
 * This reads all stored logs, so wrap it in CachedRetentionVolumeAnalyzer
 * when serving it from the event loop.
 */
final class FileRetentionVolumeAnalyzer implements RetentionVolumeAnalyzerInterface
{
    private const EMPTY_DAY    = ['count' => 0, 'bytes' => 0, 'due_count' => 0, 'due_bytes' => 0, 'policies' => []];
    private const EMPTY_POLICY = ['matched_count' => 0, 'matched_bytes' => 0, 'due_count' => 0, 'due_bytes' => 0];

    public function __construct(private readonly string $basePath) {}

    public function analyze(array $policies, int $days): RetentionVolumeReport
    {
        $active = array_values(array_filter($policies, fn(RetentionPolicy $p) => !$p->isEmpty()));
        $anyAge = self::withoutCutoffs($active);
        $daily  = [];

        foreach (JsonlDayFileScanner::listDayFiles($this->basePath) as [$fileDate, $filePath]) {
            $this->scanFile($filePath, $fileDate->format('Y-m-d'), $active, $anyAge, $daily);
        }

        return RetentionVolumeReport::fromDailyTotals(
            $policies,
            $daily,
            $days,
            RetentionVolumeReport::SIZE_BASIS_FILE,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Field-only twins (no cutoff) that tell what each policy matches at any age.
     *
     * @param  RetentionPolicy[] $policies
     * @return array<string, RetentionPolicy>
     */
    private static function withoutCutoffs(array $policies): array
    {
        $twins = [];
        foreach ($policies as $policy) {
            $twins[$policy->name] = new RetentionPolicy(
                name:         $policy->name,
                appKey:       $policy->appKey,
                appId:        $policy->appId,
                level:        $policy->level,
                category:     $policy->category,
                messageRegex: $policy->messageRegex,
                messageGlob:  $policy->messageGlob,
            );
        }
        return $twins;
    }

    /**
     * @param RetentionPolicy[]              $active
     * @param array<string, RetentionPolicy> $anyAge
     * @param array<string, array>           $daily
     */
    private function scanFile(string $filePath, string $fileDate, array $active, array $anyAge, array &$daily): void
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                self::tally($trimmed, $fileDate, $active, $anyAge, $daily);
            }
        }

        fclose($handle);
    }

    /**
     * Adds one JSONL line to its day's totals and to every policy it matches.
     *
     * @param RetentionPolicy[]              $active
     * @param array<string, RetentionPolicy> $anyAge
     * @param array<string, array>           $daily
     */
    private static function tally(string $line, string $fileDate, array $active, array $anyAge, array &$daily): void
    {
        $bytes = strlen($line) + 1;
        $entry = json_decode($line, true);
        $entry = is_array($entry) ? $entry : [];
        $date  = self::entryDate($entry) ?? $fileDate;

        $day = $daily[$date] ?? self::EMPTY_DAY;
        $day['count']++;
        $day['bytes'] += $bytes;

        $due = false;
        foreach ($entry === [] ? [] : $active as $policy) {
            $twin = $anyAge[$policy->name];
            if ($twin->hasFieldFilters() && !EntryMatcher::matches($twin, $entry)) {
                continue;
            }

            $stats = $day['policies'][$policy->name] ?? self::EMPTY_POLICY;
            $stats['matched_count']++;
            $stats['matched_bytes'] += $bytes;

            if (EntryMatcher::shouldDelete($policy, $entry)) {
                $stats['due_count']++;
                $stats['due_bytes'] += $bytes;
                $due = true;
            }

            $day['policies'][$policy->name] = $stats;
        }

        if ($due) {
            $day['due_count']++;
            $day['due_bytes'] += $bytes;
        }

        $daily[$date] = $day;
    }

    private static function entryDate(array $entry): ?string
    {
        $raw = $entry['timestamp'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
