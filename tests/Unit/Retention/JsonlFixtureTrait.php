<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

/**
 * Fixture helpers shared by tests that exercise a {basePath}/YYYY/MM/DD.jsonl
 * log directory (FileStoragePruner, FileRetentionEngine).
 */
trait JsonlFixtureTrait
{
    /** @param array<int,array> $entries */
    private function writeEntries(string $relativePath, array $entries): void
    {
        $path = $this->tmpDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) . '.jsonl';
        @mkdir(dirname($path), 0755, true);

        $lines = array_map(
            fn(array $e) => json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $entries,
        );
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** @return array<int,array> */
    private function readEntries(string $relativePath): array
    {
        $path = $this->tmpDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) . '.jsonl';
        if (!file_exists($path)) {
            return [];
        }
        $lines   = explode("\n", trim((string) file_get_contents($path)));
        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    private function makeEntry(
        string $timestamp = '2024-03-01T10:00:00.000Z',
        string $appKey    = 'test-app',
        string $appId     = 'test',
        string $level     = 'info',
        string $category  = 'general',
        string $message   = 'Test message',
    ): array {
        return [
            'id'         => uniqid('', true),
            'trace_id'   => 'trace-default',
            'batch_id'   => null,
            'app_key'    => $appKey,
            'app_id'     => $appId,
            'user_agent' => 'TestClient/1.0',
            'level'      => $level,
            'category'   => $category,
            'message'    => $message,
            'context'    => null,
            'timestamp'  => $timestamp,
            'created_at' => $timestamp,
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
