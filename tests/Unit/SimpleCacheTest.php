<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use Omegaalfa\Cache\Exception\InvalidKeyException;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\ArrayStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimpleCacheTest extends TestCase
{
    private FrozenClock$clock;
    private SimpleCache$cache;
    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->cache = new SimpleCache(new ArrayStore($this->clock), clock: $this->clock);
    }
    #[DataProvider('values')]public function testPreservesValues(mixed$value): void
    {
        self::assertTrue($this->cache->set('key', $value));
        self::assertSame($value, $this->cache->get('key'));
        self::assertTrue($this->cache->has('key'));
    }/** @return iterable<array{mixed}> */ public static function values(): iterable
    {
        yield[null];
        yield[false];
        yield[0];
        yield[''];
        yield[[]];
        yield[['a' => 1]];
    }
    public function testMissAndDefault(): void
    {
        self::assertSame('default', $this->cache->get('missing', 'default'));
        self::assertFalse($this->cache->has('missing'));
    }
    public function testIntegerAndIntervalTtl(): void
    {
        $this->cache->set('a', 'v', 10);
        $this->cache->set('b', 'v', new DateInterval('PT10S'));
        $this->clock->advance('+10 seconds');
        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
    }
    public function testNonPositiveTtlDeletes(): void
    {
        $this->cache->set('a', 'old');
        $this->cache->set('a', 'new', 0);
        self::assertNull($this->cache->get('a'));
    }
    public function testMultiple(): void
    {
        self::assertTrue($this->cache->setMultiple(['a' => 1,'b' => false]));
        self::assertSame(['a' => 1,'b' => false,'c' => 'x'], $this->cache->getMultiple(['a','b','c'], 'x'));
        self::assertTrue($this->cache->deleteMultiple(['a','b']));
        self::assertFalse($this->cache->has('a'));
    }
    public function testClear(): void
    {
        $this->cache->set('a', 1);
        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('a'));
    }
    public function testReservedKeyIsRejected(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->cache->get('bad:key');
    }
    public function testRemember(): void
    {
        $calls = 0;
        self::assertSame(42, $this->cache->remember('a', 10, function () use (&$calls) {
            $calls++;
            return 42;
        }));
        self::assertSame(42, $this->cache->remember('a', 10, function () use (&$calls) {
            $calls++;
            return 0;
        }));
        self::assertSame(1,$calls);
    }
}
