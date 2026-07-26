<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Compliance;

use Cache\IntegrationTests\CachePoolTest;
use Omegaalfa\Cache\CacheItemPool;
use Omegaalfa\Cache\Store\ArrayStore;
use Psr\Cache\CacheItemPoolInterface;

final class ExternalPsr6ComplianceTest extends CachePoolTest
{
    private ?ArrayStore $store = null;

    /** @return list<array{string}> */
    public static function invalidKeys(): array
    {
        return [
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

    public function createCachePool(): CacheItemPoolInterface
    {
        $this->skippedTests = [
            'testDeferredSaveWithoutCommit' => 'Destructor commits can hide persistence failures and are intentionally unsupported.',
        ];
        $this->store ??= new ArrayStore();

        return new CacheItemPool($this->store);
    }
}
