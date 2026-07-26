<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Integration;

use DateTimeImmutable;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\ApcuStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('apcu')]
final class ApcuStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (
            !extension_loaded('apcu')
            || !filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)
            || !filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL)
        ) {
            self::markTestSkipped('APCu with apc.enable_cli=1 is unavailable.');
        }
    }

    public function testHitMissFalsyValuesOverwriteAndDelete(): void
    {
        $cache = $this->cache();

        self::assertNull($cache->get('missing'));
        self::assertTrue($cache->set('key', null));
        self::assertNull($cache->get('key', 'default'));
        self::assertTrue($cache->has('key'));

        self::assertTrue($cache->set('key', false));
        self::assertFalse($cache->get('key'));
        self::assertTrue($cache->delete('key'));
        self::assertFalse($cache->has('key'));
    }

    public function testBulkOperationsAndClearAreNamespaced(): void
    {
        $prefix = $this->prefix();
        $first = new SimpleCache(new ApcuStore($prefix . 'first:'));
        $second = new SimpleCache(new ApcuStore($prefix . 'second:'));

        self::assertTrue($first->setMultiple(['a' => 1, 'b' => false], 60));
        self::assertSame(
            ['a' => 1, 'b' => false, 'missing' => 'default'],
            $first->getMultiple(['a', 'b', 'missing'], 'default'),
        );
        self::assertTrue($second->set('a', 'isolated'));

        self::assertTrue($first->deleteMultiple(['b']));
        self::assertFalse($first->has('b'));
        self::assertTrue($first->clear());
        self::assertFalse($first->has('a'));
        self::assertSame('isolated', $second->get('a'));
    }

    public function testExpirationUsesInjectedClock(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $cache = new SimpleCache(
            new ApcuStore($this->prefix(), $clock),
            clock: $clock,
        );

        self::assertTrue($cache->set('key', 'value', 10));
        self::assertSame('value', $cache->get('key'));

        $clock->advance('+10 seconds');

        self::assertNull($cache->get('key'));
    }

    private function cache(): SimpleCache
    {
        return new SimpleCache(new ApcuStore($this->prefix()));
    }

    private function prefix(): string
    {
        return 'omega-apcu-test:' . bin2hex(random_bytes(6)) . ':';
    }
}
