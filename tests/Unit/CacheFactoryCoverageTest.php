<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use Omegaalfa\Cache\CacheFactory;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\Exception\BackendUnavailableException;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\SimpleCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheFactoryCoverageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/omega-factory-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testDirectFileRedisAndExternalRedisFactories(): void
    {
        $file = CacheFactory::file(new FileConfig($this->directory, gcProbability: 0));
        self::assertTrue($file->set('key', 'value'));
        self::assertSame('value', $file->get('key'));

        self::assertInstanceOf(
            SimpleCache::class,
            CacheFactory::redis(new RedisConfig(prefix: 'factory:')),
        );
        self::assertInstanceOf(
            SimpleCache::class,
            CacheFactory::fromRedis(new \Redis(), 'external:'),
        );
    }

    public function testArrayConfigurationCreatesFileAndRedisDrivers(): void
    {
        $file = CacheFactory::create([
            'driver' => 'file',
            'file' => [
                'directory' => $this->directory,
                'directoryMode' => 0770,
                'fileMode' => 0660,
                'gcProbability' => 0,
                'gcDivisor' => 10,
            ],
        ]);
        self::assertTrue($file->set('key', 'value'));
        self::assertSame('value', $file->get('key'));

        $redis = CacheFactory::create([
            'driver' => 'redis',
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => null,
                'database' => 0,
                'timeout' => 1,
                'readTimeout' => 1.5,
                'persistent' => false,
                'persistentId' => null,
                'prefix' => 'factory:',
                'tls' => false,
                'options' => [],
            ],
        ]);
        self::assertInstanceOf(SimpleCache::class, $redis);
    }

    public function testApcuFactoryFailsExplicitlyWithoutExtension(): void
    {
        if (extension_loaded('apcu')) {
            self::markTestSkipped('This test targets the no-APCu environment.');
        }

        $this->expectException(BackendUnavailableException::class);
        CacheFactory::create(['driver' => 'apcu', 'prefix' => 'factory:']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'file config is not array' => [['driver' => 'file', 'file' => 'invalid']];
        yield 'file numeric key' => [['driver' => 'file', 'file' => [0 => 'invalid']]];
        yield 'file missing directory' => [['driver' => 'file', 'file' => []]];
        yield 'file invalid directory' => [['driver' => 'file', 'file' => ['directory' => 1]]];
        yield 'file invalid mode' => [['driver' => 'file', 'file' => ['directory' => '/tmp/cache', 'fileMode' => '660']]];
        yield 'redis options' => [['driver' => 'redis', 'redis' => ['options' => 'invalid']]];
        yield 'redis host' => [['driver' => 'redis', 'redis' => ['host' => 1]]];
        yield 'redis timeout' => [['driver' => 'redis', 'redis' => ['timeout' => '1']]];
        yield 'redis bool' => [['driver' => 'redis', 'redis' => ['persistent' => 1]]];
    }

    /** @param array<string, mixed> $configuration */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidNestedConfigurationIsRejected(array $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);
        CacheFactory::create($configuration);
    }
}
