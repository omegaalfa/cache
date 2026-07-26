<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\Internal\CacheEntry;
use Omegaalfa\Cache\Store\NullStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationAndNullStoreTest extends TestCase
{
    /** @return iterable<string, array{callable(): object}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty file directory' => [static fn(): FileConfig => new FileConfig('')];
        yield 'invalid gc probability' => [static fn(): FileConfig => new FileConfig('/tmp/cache', gcProbability: 2, gcDivisor: 1)];
        yield 'empty redis host' => [static fn(): RedisConfig => new RedisConfig(host: '')];
        yield 'invalid redis port' => [static fn(): RedisConfig => new RedisConfig(port: 70_000)];
        yield 'invalid redis database' => [static fn(): RedisConfig => new RedisConfig(database: -1)];
        yield 'invalid redis timeout' => [static fn(): RedisConfig => new RedisConfig(timeout: -1.0)];
        yield 'empty redis prefix' => [static fn(): RedisConfig => new RedisConfig(prefix: '')];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory();
    }

    public function testConfigurationPreservesValues(): void
    {
        $file = new FileConfig('/tmp/cache', 0750, 0640, 0, 10);
        $redis = new RedisConfig(
            host: 'redis.internal',
            port: 6380,
            password: 'secret',
            database: 4,
            timeout: 1.5,
            readTimeout: 2.5,
            persistent: true,
            persistentId: 'worker',
            prefix: 'app:',
            tls: true,
            options: [1 => 2],
        );

        self::assertSame('/tmp/cache', $file->directory);
        self::assertSame(0750, $file->directoryMode);
        self::assertSame('redis.internal', $redis->host);
        self::assertSame('secret', $redis->password);
        self::assertTrue($redis->persistent);
        self::assertTrue($redis->tls);
    }

    public function testNullStoreImplementsEveryOperationAsSuccessfulMiss(): void
    {
        $store = new NullStore();
        $entry = new CacheEntry('payload', null, time());

        self::assertNull($store->get('key'));
        self::assertSame([], $store->getMultiple(['key']));
        self::assertTrue($store->set('key', $entry));
        self::assertTrue($store->setMultiple(['key' => $entry]));
        self::assertTrue($store->delete('key'));
        self::assertTrue($store->deleteMultiple(['key']));
        self::assertTrue($store->clear());
    }
}
