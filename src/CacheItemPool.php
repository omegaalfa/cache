<?php

declare(strict_types=1);

namespace Omegaalfa\Cache;

use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Contract\SerializerInterface;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Internal\CacheEntry;
use Omegaalfa\Cache\Internal\Engine;
use Omegaalfa\Cache\Internal\KeyValidator;
use Omegaalfa\Cache\Serializer\NativeSerializer;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

final class CacheItemPool implements CacheItemPoolInterface
{
    private readonly Engine $engine;
    /** @var array<string,CacheItem> */
    private array $deferred = [];

    public function __construct(StoreInterface $store, ?SerializerInterface $serializer = null, ?ClockInterface $clock = null)
    {
        $this->engine = new Engine($store, $serializer ?? new NativeSerializer(), $clock ?? new SystemClock());
    }

    public function getItem(string $key): CacheItemInterface
    {
        KeyValidator::validate($key);
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }
        [$hit, $value] = $this->engine->read($key);
        $entry = $this->engine->store->get($key);
        return new CacheItem($key, $value, $hit, $entry?->expiresAt, $this->engine->clock);
    }

    /** @return array<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            KeyValidator::validate($key);
            $out[$key] = $this->getItem($key);
        }
        return $out;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return $this->engine->store->clear();
    }

    public function deleteItem(string $key): bool
    {
        KeyValidator::validate($key);
        unset($this->deferred[$key]);
        return $this->engine->store->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            KeyValidator::validate($key);
        }
        foreach ($keys as $key) {
            unset($this->deferred[$key]);
        }
        return $this->engine->store->deleteMultiple(array_values($keys));
    }

    public function save(CacheItemInterface $item): bool
    {
        $owned = $this->owned($item);
        unset($this->deferred[$owned->getKey()]);
        $now = $this->engine->clock->now()->getTimestamp();
        $entry = new CacheEntry($this->engine->serializer->serialize($owned->get()), $owned->expiration(), $now);
        return $this->engine->store->set($owned->getKey(), $entry);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        $owned = $this->owned($item);
        $this->deferred[$owned->getKey()] = new CacheItem(
            $owned->getKey(),
            $owned->get(),
            true,
            $owned->expiration(),
            $this->engine->clock,
        );
        return true;
    }

    public function commit(): bool
    {
        if ($this->deferred === []) {
            return true;
        }
        $entries = [];
        $now = $this->engine->clock->now()->getTimestamp();
        foreach ($this->deferred as $key => $item) {
            $entries[$key] = new CacheEntry($this->engine->serializer->serialize($item->get()), $item->expiration(), $now);
        }
        $ok = $this->engine->store->setMultiple($entries);
        if ($ok) {
            $this->deferred = [];
        }
        return $ok;
    }

    private function owned(CacheItemInterface $item): CacheItem
    {
        if (!$item instanceof CacheItem) {
            throw new Exception\InvalidArgumentException('This pool only accepts CacheItem instances created by omegaalfa/cache.');
        }
        return $item;
    }
}
