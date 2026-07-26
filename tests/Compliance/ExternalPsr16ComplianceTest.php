<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Compliance;

use Cache\IntegrationTests\SimpleCacheTest as BaseSimpleCacheTest;
use DateTimeImmutable;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\ArrayStore;
use Omegaalfa\Cache\Tests\Support\FrozenClock;
use Psr\SimpleCache\CacheInterface;

final class ExternalPsr16ComplianceTest extends BaseSimpleCacheTest
{
    private FrozenClock $clock;

    /** @return list<array{string}> */
    public static function invalidKeys(): array
    {
        return self::invalidArrayKeys();
    }

    /** @return list<array{string}> */
    public static function invalidArrayKeys(): array
    {
        return [
            [''],
            ['{str'],
            ['rand}str'],
            ['rand(str'],
            ['rand)str'],
            ['rand/str'],
            ['rand\\str'],
            ['rand@str'],
            ['rand:str'],
        ];
    }

    /** @return list<array{string}> */
    public static function invalidTtl(): array
    {
        return [['legacy-invalid-ttl-type']];
    }

    public function createSimpleCache(): CacheInterface
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->skippedTests = [
            'testSetMultipleWithIntegerArrayKey' => 'Numeric PHP array keys cannot satisfy the PSR v3 string-key contract.',
            'testGetMultipleNoIterable' => 'PSR v3 iterable type rejects this before the method body.',
            'testSetMultipleNoIterable' => 'PSR v3 iterable type rejects this before the method body.',
            'testDeleteMultipleNoIterable' => 'PSR v3 iterable type rejects this before the method body.',
            'testSetInvalidTtl' => 'PSR v3 TTL union type rejects legacy values before the method body.',
            'testSetMultipleInvalidTtl' => 'PSR v3 TTL union type rejects legacy values before the method body.',
        ];

        return new SimpleCache(
            new ArrayStore($this->clock),
            clock: $this->clock,
        );
    }

    public function advanceTime($seconds): void
    {
        $this->clock->advance("+{$seconds} seconds");
    }
}
