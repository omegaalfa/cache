<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use Omegaalfa\Cache\CacheItemPool;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Exception\SerializationException;
use Omegaalfa\Cache\Internal\CacheEntry;
use Omegaalfa\Cache\Internal\Expiration;
use Omegaalfa\Cache\Serializer\NativeSerializer;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\ArrayStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use ReflectionClass;

final class CentralErrorPathTest extends TestCase
{
    public function testSimpleCacheRejectsNonStringSetMultipleKey(): void
    {
        $cache = new SimpleCache(new ArrayStore());

        $this->expectException(InvalidArgumentException::class);
        $cache->setMultiple([0 => 'value']);
    }

    public function testSimpleCacheRejectsNonStringBulkKey(): void
    {
        $cache = new SimpleCache(new ArrayStore());

        $this->expectException(InvalidArgumentException::class);
        $cache->getMultiple([1]);
    }

    public function testPoolDeleteItemsAndForeignItemRejection(): void
    {
        $pool = new CacheItemPool(new ArrayStore());
        $pool->save($pool->getItem('a')->set(1));
        $pool->saveDeferred($pool->getItem('b')->set(2));

        self::assertTrue($pool->deleteItems(['a', 'b']));
        self::assertFalse($pool->hasItem('a'));
        self::assertFalse($pool->getItem('b')->isHit());

        $this->expectException(InvalidArgumentException::class);
        $pool->save($this->createStub(CacheItemInterface::class));
    }

    public function testDeferredQueueSurvivesFailedCommit(): void
    {
        $store = new class implements StoreInterface {
            public int $attempts = 0;

            public function get(string $key): ?CacheEntry
            {
                return null;
            }

            public function getMultiple(array $keys): array
            {
                return [];
            }

            public function set(string $key, CacheEntry $entry): bool
            {
                return true;
            }

            public function setMultiple(array $entries): bool
            {
                return ++$this->attempts > 1;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }
        };

        $pool = new CacheItemPool($store);
        $pool->saveDeferred($pool->getItem('key')->set('value'));

        self::assertFalse($pool->commit());
        self::assertSame('value', $pool->getItem('key')->get());
        self::assertTrue($pool->commit());
        self::assertSame(2, $store->attempts);
    }

    public function testInvalidDateIntervalIsWrapped(): void
    {
        $interval = (new ReflectionClass(DateInterval::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(DateInterval::class, $interval);

        $this->expectException(InvalidArgumentException::class);
        Expiration::after($interval, new DateTimeImmutable());
    }

    public function testNativeSerializerWrapsSerializationFailure(): void
    {
        $this->expectException(SerializationException::class);
        (new NativeSerializer())->serialize(static fn(): int => 1);
    }
}
