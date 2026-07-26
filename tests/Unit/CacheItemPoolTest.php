<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use DateTimeImmutable;
use Omegaalfa\Cache\CacheItemPool;
use Omegaalfa\Cache\Store\ArrayStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CacheItemPoolTest extends TestCase
{
    public function testHitMissSaveDeleteAndClear(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01'));
        $pool = new CacheItemPool(new ArrayStore($clock), clock: $clock);
        $miss = $pool->getItem('a');
        self::assertFalse($miss->isHit());
        $miss->set(false)->expiresAfter(10);
        self::assertTrue($pool->save($miss));
        $hit = $pool->getItem('a');
        self::assertTrue($hit->isHit());
        self::assertFalse($hit->get());
        self::assertTrue($pool->hasItem('a'));
        self::assertTrue($pool->deleteItem('a'));
        self::assertFalse($pool->hasItem('a'));
        $pool->save($pool->getItem('b')->set(1));
        self::assertTrue($pool->clear());
        self::assertFalse($pool->hasItem('b'));
    }
    public function testDeferredIsVisibleAndCommitted(): void
    {
        $pool = new CacheItemPool(new ArrayStore());
        $item = $pool->getItem('a')->set('v');
        self::assertTrue($pool->saveDeferred($item));
        self::assertSame('v', $pool->getItem('a')->get());
        self::assertTrue($pool->commit());
        self::assertTrue($pool->getItem('a')->isHit());
        self::assertTrue($pool->commit());
    }
    public function testGetItemsReturnsMisses(): void
    {
        $pool = new CacheItemPool(new ArrayStore());
        $items = $pool->getItems(['a','b']);
        self::assertSame(['a','b'], array_keys($items));
        self::assertFalse($items['a']->isHit());
    }
}
