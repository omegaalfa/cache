<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Integration;

use DateTimeImmutable;
use FilesystemIterator;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\FileStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Group('concurrency')]
final class FileStoreConcurrencyTest extends TestCase
{
    private const ITERATIONS = 80;

    private string $directory;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $this->directory = sys_get_temp_dir() . '/omega-cache-concurrency-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testSeveralProcessesWritingTheSameKey(): void
    {
        $this->runWorkers([
            ['write', 'shared', '1'],
            ['write', 'shared', '2'],
            ['write', 'shared', '3'],
            ['write', 'shared', '4'],
        ]);

        $value = $this->cache()->get('shared');
        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^writer-\d+-\d+\|x{4096}\|END$/D', $value);
    }

    public function testReadersNeverObservePartialWrites(): void
    {
        $this->cache()->set('shared', 'writer-0-0|' . str_repeat('x', 4_096) . '|END');

        $this->runWorkers([
            ['write', 'shared', '1'],
            ['read', 'shared', '2'],
            ['read', 'shared', '3'],
            ['read', 'shared', '4'],
        ]);

        self::assertNotNull($this->cache()->get('shared'));
    }

    public function testDeleteCanCompeteWithWritesAndReads(): void
    {
        $this->runWorkers([
            ['write', 'shared', '1'],
            ['delete', 'shared', '2'],
            ['read', 'shared', '3'],
        ]);

        $value = $this->cache()->get('shared');
        self::assertTrue($value === null || is_string($value));
    }

    public function testClearCanCompeteWithWritesAndExistingInstances(): void
    {
        $existing = $this->cache();
        $existing->set('before', 'writer-0-0|' . str_repeat('x', 4_096) . '|END');

        $this->runWorkers([
            ['write', 'shared', '1'],
            ['clear', 'shared', '2'],
            ['read', 'shared', '3'],
        ]);

        self::assertNull($existing->get('before'));
        $value = $this->cache()->get('shared');
        self::assertTrue($value === null || is_string($value));
    }

    public function testTtlRemainsConsistentDuringConcurrentAccess(): void
    {
        $this->runWorkers([
            ['ttl-write', 'shared', '1'],
            ['read', 'shared', '2'],
            ['read', 'shared', '3'],
        ]);

        $clock = new FrozenClock(new DateTimeImmutable('+1 hour'));
        $cache = new SimpleCache(
            new FileStore(
                new FileConfig($this->directory, gcProbability: 0),
                $clock,
            ),
            clock: $clock,
        );

        self::assertNull($cache->get('shared'));
    }

    /**
     * @param list<array{string, string, string}> $workers
     */
    private function runWorkers(array $workers): void
    {
        $processes = [];
        foreach ($workers as [$operation, $key, $worker]) {
            $command = [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/file_store_worker.php',
                $operation,
                $this->directory,
                $key,
                $worker,
                (string) self::ITERATIONS,
            ];
            $pipes = [];
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes[1], $pipes[2]];
        }

        foreach ($processes as [$process, $stdout, $stderr]) {
            $output = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);

            self::assertSame(
                0,
                proc_close($process),
                "Worker failed. stdout={$output}; stderr={$error}",
            );
        }
    }

    private function cache(): SimpleCache
    {
        return new SimpleCache(
            new FileStore(new FileConfig($this->directory, gcProbability: 0)),
        );
    }
}
