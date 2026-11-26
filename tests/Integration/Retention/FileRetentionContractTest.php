<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Retention;

use LogService\Retention\FileRetentionEngine;
use LogService\Retention\FileRetentionVolumeAnalyzer;
use LogService\Retention\FileStoragePruner;
use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\FileStorage;
use LogService\Storage\StorageInterface;

final class FileRetentionContractTest extends RetentionContractTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logstream_retention_' . uniqid();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    protected function storage(): StorageInterface
    {
        return new FileStorage($this->dir);
    }

    protected function engine(): RetentionEngineInterface
    {
        return new FileRetentionEngine($this->dir);
    }

    protected function pruner(): PrunerInterface
    {
        return new FileStoragePruner($this->dir);
    }

    protected function analyzer(): RetentionVolumeAnalyzerInterface
    {
        return new FileRetentionVolumeAnalyzer($this->dir);
    }
}
