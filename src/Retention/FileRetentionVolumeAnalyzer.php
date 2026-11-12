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
    public function __construct(private readonly string $basePath) {}

    public function analyze(array $policies, int $days): RetentionVolumeReport
    {
        $daily = [];

        // Field-only twins (no cutoff) tell what each policy matches at any age.
        $anyAge = [];
        foreach ($policies as $policy) {
            $anyAge[$policy->name] = new RetentionPolicy(
                name:         $policy->name,
                appKey:       $policy->appKey,
                appId:        $policy->appId,
                level:        $policy->level,
                category:     $policy->category,
                messageRegex: $policy->messageRegex,
                messageGlob:  $policy->messageGlob,
            );
        }

        foreach (JsonlDayFileScanner::listDayFiles($this->basePath) as [$fileDate, $filePath]) {
            $handle = @fopen($filePath, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $bytes = strlen($trimmed) + 1;
                $entry = json_decode($trimmed, true);
                $entry = is_array($entry) ? $entry : [];
                $date  = self::entryDate($entry) ?? $fileDate->format('Y-m-d');

                $daily[$date] ??= ['count' => 0, 'bytes' => 0, 'due_count' => 0, 'due_bytes' => 0, 'policies' => []];
                $daily[$date]['count']++;
                $daily[$date]['bytes'] += $bytes;

                if ($entry === []) {
                    continue;
                }

                $due = false;
                foreach ($policies as $policy) {
                    if ($policy->isEmpty()) {
                        continue;
                    }

                    $matched = !$anyAge[$policy->name]->hasFieldFilters()
                        || EntryMatcher::matches($anyAge[$policy->name], $entry);
                    if (!$matched) {
                        continue;
                    }

                    $stats = $daily[$date]['policies'][$policy->name]
                        ?? ['matched_count' => 0, 'matched_bytes' => 0, 'due_count' => 0, 'due_bytes' => 0];
                    $stats['matched_count']++;
                    $stats['matched_bytes'] += $bytes;

                    if (EntryMatcher::shouldDelete($policy, $entry)) {
                        $stats['due_count']++;
                        $stats['due_bytes'] += $bytes;
                        $due = true;
                    }

                    $daily[$date]['policies'][$policy->name] = $stats;
                }

                if ($due) {
                    $daily[$date]['due_count']++;
                    $daily[$date]['due_bytes'] += $bytes;
                }
            }

            fclose($handle);
        }

        return RetentionVolumeReport::fromDailyTotals(
            $policies,
            $daily,
            $days,
            RetentionVolumeReport::SIZE_BASIS_FILE,
        );
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
