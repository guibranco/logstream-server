<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Storage\FileStorage;
use LogService\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\Test;

final class FileStorageContextSearchTest extends ContextSearchTestCase
{
    private ?FileStorage $storage = null;
    private string $dir;

    protected function storage(): StorageInterface
    {
        if ($this->storage === null) {
            $this->dir     = sys_get_temp_dir() . '/logstream_it_' . uniqid();
            $this->storage = new FileStorage($this->dir);
        }

        return $this->storage;
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    #[Test]
    public function search_matches_substrings_inside_words(): void
    {
        // File storage scans text, so "card" also hits "card_declined".
        // MariaDB matches whole words only (see the MariaDB test).
        self::assertSame(['AA', 'BB'], $this->searchIds('context_search=card'));
    }
}
