<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Unit;

use Omegaalfa\Cache\Exception\BackendUnavailableException;
use Omegaalfa\Cache\Exception\CacheException;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Exception\InvalidKeyException;
use Omegaalfa\Cache\Exception\RedisConnectionException;
use Omegaalfa\Cache\Store\ApcuStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException as Psr6CacheException;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\CacheException as Psr16CacheException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

final class ExceptionAndOptionalStoreTest extends TestCase
{
    public function testExceptionsImplementBothPsrContracts(): void
    {
        $cache = new CacheException();
        $invalid = new InvalidArgumentException();
        $key = new InvalidKeyException();
        $backend = new BackendUnavailableException();
        $redis = new RedisConnectionException();

        self::assertInstanceOf(Psr6CacheException::class, $cache);
        self::assertInstanceOf(Psr16CacheException::class, $cache);
        self::assertInstanceOf(Psr6InvalidArgumentException::class, $invalid);
        self::assertInstanceOf(Psr16InvalidArgumentException::class, $invalid);
        self::assertInstanceOf(InvalidArgumentException::class, $key);
        self::assertInstanceOf(CacheException::class, $backend);
        self::assertInstanceOf(BackendUnavailableException::class, $redis);
    }

    public function testApcuFailsClearlyWhenExtensionIsUnavailable(): void
    {
        if (extension_loaded('apcu')) {
            self::markTestSkipped('This test targets the environment without ext-apcu.');
        }

        $this->expectException(BackendUnavailableException::class);
        new ApcuStore();
    }
}
