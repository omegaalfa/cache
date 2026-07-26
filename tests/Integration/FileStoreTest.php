<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Integration;

use DateTimeImmutable;
use FilesystemIterator;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Exception\CorruptedCacheEntryException;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\FileStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FileStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/omega-cache-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testPersistenceOverwriteDeleteAndNamespaceClear(): void
    {
        $config = new FileConfig($this->dir, gcProbability: 0);
        $first = new SimpleCache(new FileStore($config));
        $first->set('key', ['v' => 1]);
        $first->set('key', ['v' => 2]);

        $second = new SimpleCache(new FileStore($config));
        self::assertSame(['v' => 2], $second->get('key'));
        self::assertTrue($second->delete('key'));
        self::assertNull($second->get('key'));

        $second->set('another', false);
        self::assertTrue($second->clear());
        self::assertNull($second->get('another'));
        self::assertNull((new SimpleCache(new FileStore($config)))->get('another'));
    }

    public function testExpirationUsesInjectedClock(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $cache = new SimpleCache(
            new FileStore(new FileConfig($this->dir, gcProbability: 0), $clock),
            clock: $clock,
        );

        $cache->set('key', 'value', 10);
        self::assertSame('value', $cache->get('key'));

        $clock->advance('+10 seconds');
        self::assertNull($cache->get('key'));
    }

    public function testKeyNeverBecomesPath(): void
    {
        $cache = new SimpleCache(new FileStore(new FileConfig($this->dir, gcProbability: 0)));
        $cache->set('unicode-? space', 'safe');

        self::assertSame('safe', $cache->get('unicode-? space'));
        self::assertFileDoesNotExist($this->dir . '/unicode-? space');
    }

    public function testCorruptedFileIsRejected(): void
    {
        $cache = new SimpleCache(new FileStore(new FileConfig($this->dir, gcProbability: 0)));
        $cache->set('key', 'value');

        $path = $this->cacheFile();
        file_put_contents($path, "invalid\ncontent");

        $this->expectException(CorruptedCacheEntryException::class);
        $cache->get('key');
    }

    public function testForcedGarbageCollectionRemovesExpiredFiles(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $cache = new SimpleCache(
            new FileStore(
                new FileConfig($this->dir, gcProbability: 1, gcDivisor: 1),
                $clock,
            ),
            clock: $clock,
        );

        $cache->set('expired', 'old', 10);
        $clock->advance('+10 seconds');
        $cache->set('valid', 'new', 60);

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'cache') {
                $files[] = $file->getPathname();
            }
        }

        self::assertCount(1, $files);
        self::assertSame('new', $cache->get('valid'));
    }

    private function cacheFile(): string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'cache') {
                return $file->getPathname();
            }
        }

        self::fail('No cache file was created.');
    }
}
