<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use Omegaalfa\Cache\CacheFactory;
use Omegaalfa\Cache\CacheManager;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\SimpleCache;
use PHPUnit\Framework\TestCase;

final class FactoryManagerTest extends TestCase
{
    public function testFactoryRequiresExplicitValidDriver(): void
    {
        foreach ([[], ['driver' => 42], ['driver' => 'unknown']] as $config) {
            try {
                CacheFactory::create($config);
                self::fail('Invalid factory configuration should fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFactoryCreatesArrayAndNullStores(): void
    {
        $array = CacheFactory::create(['driver' => 'array', 'max_entries' => 2]);
        $null = CacheFactory::create(['driver' => 'null']);

        self::assertTrue($array->set('key', 'value'));
        self::assertSame('value', $array->get('key'));
        self::assertTrue($null->set('key', 'value'));
        self::assertNull($null->get('key'));
    }

    public function testFactoryRejectsCoerciveConfigurationTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CacheFactory::create(['driver' => 'array', 'max_entries' => '2']);
    }

    public function testManagerIsLazyAndCachesEachInstance(): void
    {
        $calls = 0;
        $manager = new CacheManager('array', [
            'array' => static function () use (&$calls): SimpleCache {
                ++$calls;

                return CacheFactory::array();
            },
        ]);

        self::assertSame($manager->store(), $manager->store('array'));
        self::assertSame(1, $calls);
    }

    public function testManagerRejectsUnknownStoreAndInvalidFactory(): void
    {
        $manager = new CacheManager('invalid', ['invalid' => static fn(): string => 'bad']);

        try {
            $manager->store('missing');
            self::fail('Unknown store should fail.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $manager->store();
    }
}
