<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Integration;

use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\Exception\RedisConnectionException;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\RedisStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('redis')]
final class RedisStoreTest extends TestCase
{
    private SimpleCache $cache;
    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis unavailable');
        }

        $this->prefix = 'omega-test:' . bin2hex(random_bytes(6)) . ':';
        $this->cache = new SimpleCache(new RedisStore($this->config()));
    }

    public function testSetGetMissOverwriteDeleteAndSerializedValues(): void
    {
        self::assertNull($this->cache->get('missing'));
        self::assertTrue($this->cache->set('key', ['value' => 1]));
        self::assertSame(['value' => 1], $this->cache->get('key'));
        self::assertTrue($this->cache->has('key'));

        self::assertTrue($this->cache->set('key', false));
        self::assertFalse($this->cache->get('key'));

        self::assertTrue($this->cache->delete('key'));
        self::assertFalse($this->cache->has('key'));
    }

    public function testBulkOperationsAndNamespaceClear(): void
    {
        self::assertTrue($this->cache->setMultiple(['a' => null, 'b' => false], 30));
        self::assertSame(
            ['a' => null, 'b' => false, 'missing' => 'default'],
            $this->cache->getMultiple(['a', 'b', 'missing'], 'default'),
        );

        self::assertTrue($this->cache->deleteMultiple(['b']));
        self::assertFalse($this->cache->has('b'));

        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('a'));
    }

    public function testFirstClearActuallyInvalidatesNamespace(): void
    {
        $cache = new SimpleCache(new RedisStore($this->config()));
        $cache->set('key', 'before-clear');

        self::assertTrue($cache->clear());
        self::assertNull($cache->get('key'));

        $freshStore = new SimpleCache(new RedisStore($this->config()));
        self::assertNull($freshStore->get('key'));
    }

    public function testPositiveAndZeroTtl(): void
    {
        self::assertTrue($this->cache->set('short', 'value', 1));
        self::assertSame('value', $this->cache->get('short'));

        sleep(2);

        self::assertNull($this->cache->get('short'));
        self::assertTrue($this->cache->set('zero', 'value', 0));
        self::assertNull($this->cache->get('zero'));
    }

    public function testConfiguredConnectionFailureIsExplicit(): void
    {
        $cache = new SimpleCache(new RedisStore(new RedisConfig(
            host: '127.0.0.1',
            port: 1,
            timeout: 0.05,
            readTimeout: 0.05,
            prefix: $this->prefix,
        )));

        $this->expectException(RedisConnectionException::class);
        $cache->get('key');
    }


    public function testPrefixAndDatabaseSelectionAreApplied(): void
    {
        $database = 9;
        $cache = new SimpleCache(new RedisStore(new RedisConfig(
            host: $this->host(),
            port: $this->port(),
            database: $database,
            prefix: $this->prefix,
        )));
        $cache->set('database-key', 'value', 30);

        $redis = new \Redis();
        self::assertTrue($redis->connect($this->host(), $this->port()));
        self::assertTrue($redis->select($database));
        $version = $redis->get($this->prefix . '__namespace_version');
        self::assertIsString($version);
        $physicalValue = $redis->get(
            $this->prefix . 'v' . $version . ':database-key',
        );
        self::assertIsString($physicalValue);
        self::assertStringContainsString('"p"', $physicalValue);

        self::assertTrue($redis->select(0));
        self::assertFalse($redis->get($this->prefix . '__namespace_version'));
    }

    public function testAuthenticationSuccessAndFailure(): void
    {
        $authPort = getenv('REDIS_AUTH_PORT');
        if ($authPort === false) {
            self::markTestSkipped('REDIS_AUTH_PORT is not configured.');
        }

        $authenticated = new SimpleCache(new RedisStore(new RedisConfig(
            host: $this->host(),
            port: (int) $authPort,
            password: 'omega-secret',
            prefix: $this->prefix,
        )));
        self::assertTrue($authenticated->set('key', 'value'));
        self::assertSame('value', $authenticated->get('key'));

        $invalid = new SimpleCache(new RedisStore(new RedisConfig(
            host: $this->host(),
            port: (int) $authPort,
            password: 'wrong-secret',
            prefix: $this->prefix,
        )));

        $this->expectException(RedisConnectionException::class);
        $invalid->get('key');
    }

    public function testConnectionTimeoutIsBounded(): void
    {
        $cache = new SimpleCache(new RedisStore(new RedisConfig(
            host: '192.0.2.1',
            port: 6379,
            timeout: 0.05,
            readTimeout: 0.05,
            prefix: $this->prefix,
        )));
        $startedAt = microtime(true);

        $this->expectException(RedisConnectionException::class);

        try {
            $cache->get('key');
        } finally {
            self::assertLessThan(2.0, microtime(true) - $startedAt);
        }
    }

    public function testFailureAfterEstablishedConnectionIsExplicit(): void
    {
        $failurePort = getenv('REDIS_FAILURE_PORT');
        if ($failurePort === false) {
            self::markTestSkipped('REDIS_FAILURE_PORT is not configured.');
        }

        $config = new RedisConfig(
            host: $this->host(),
            port: (int) $failurePort,
            prefix: $this->prefix,
        );
        $cache = new SimpleCache(new RedisStore($config));
        $cache->set('key', 'value');

        $killer = new \Redis();
        self::assertTrue($killer->connect($this->host(), (int) $failurePort));
        try {
            $killer->rawCommand('SHUTDOWN', 'NOSAVE');
        } catch (\Throwable) {
            self::addToAssertionCount(1);
        }
        usleep(100_000);

        $this->expectException(RedisConnectionException::class);
        $cache->get('key');
    }

    public function testUnexpectedExtensionResponseIsRejected(): void
    {
        $redis = new \Redis();
        self::assertTrue($redis->connect($this->host(), $this->port()));
        self::assertTrue($redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP));
        self::assertTrue($redis->set($this->prefix . '__namespace_version', 1));
        self::assertTrue($redis->set($this->prefix . 'v1:unexpected', ['invalid']));

        $cache = new SimpleCache(new RedisStore($this->config(), $redis));

        $this->expectException(RedisConnectionException::class);
        $cache->get('unexpected');
    }

    public function testMalformedRedisEnvelopeIsRejected(): void
    {
        $redis = new \Redis();
        self::assertTrue($redis->connect($this->host(), $this->port()));
        self::assertTrue($redis->set($this->prefix . '__namespace_version', 1));
        self::assertTrue($redis->set($this->prefix . 'v1:corrupted', 'not-json'));

        $cache = new SimpleCache(new RedisStore($this->config(), $redis));

        $this->expectException(\Omegaalfa\Cache\Exception\CorruptedCacheEntryException::class);
        $cache->get('corrupted');
    }

    private function host(): string
    {
        return (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    }

    private function port(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    private function config(): RedisConfig
    {
        return new RedisConfig(
            host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('REDIS_PORT') ?: 6379),
            prefix: $this->prefix,
        );
    }
}
