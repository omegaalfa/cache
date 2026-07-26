<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use DateTimeImmutable;
use Omegaalfa\Cache\Internal\CacheEntry;
use Omegaalfa\Cache\Store\ArrayStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class ArrayStoreTest extends TestCase
{
    public function testExpirationOverwriteBulkAndIsolation(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $first = new ArrayStore($clock);
        $second = new ArrayStore($clock);
        $expiresAt = $clock->now()->getTimestamp() + 10;

        self::assertTrue($first->set('a', new CacheEntry('old', null, $clock->now()->getTimestamp())));
        self::assertTrue($first->set('a', new CacheEntry('new', $expiresAt, $clock->now()->getTimestamp())));
        self::assertSame('new', $first->get('a')?->payload);
        self::assertNull($second->get('a'));

        self::assertTrue($first->setMultiple([
            'b' => new CacheEntry('b', null, $clock->now()->getTimestamp()),
            'c' => new CacheEntry('c', null, $clock->now()->getTimestamp()),
        ]));
        self::assertSame(['b', 'c'], array_keys($first->getMultiple(['b', 'c', 'missing'])));
        self::assertTrue($first->deleteMultiple(['b', 'c']));
        self::assertSame([], $first->getMultiple(['b', 'c']));

        $clock->advance('+10 seconds');
        self::assertNull($first->get('a'));
    }

    public function testMaximumEntriesEvictsOldestEntry(): void
    {
        $store = new ArrayStore(maxEntries: 2);
        $entry = new CacheEntry('value', null, time());

        $store->set('a', $entry);
        $store->set('b', $entry);
        $store->get('a');
        $store->set('c', $entry);

        self::assertNull($store->get('a'));
        self::assertNotNull($store->get('b'));
        self::assertNotNull($store->get('c'));
    }

    public function testInvalidMaximumEntriesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArrayStore(maxEntries: 0);
    }
}
