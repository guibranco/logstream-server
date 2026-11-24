<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Storage\FileStorage;
use LogService\Storage\StorageInterface;

final class FileStorageContractTest extends StorageContractTestCase
{
    private ?FileStorage $storage = null;
    private string $dir = '';

    protected function storage(): StorageInterface
    {
        if ($this->storage === null) {
            $this->dir     = sys_get_temp_dir() . '/logstream_contract_' . uniqid();
            $this->storage = new FileStorage($this->dir);
        }

        return $this->storage;
    }

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
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
}
