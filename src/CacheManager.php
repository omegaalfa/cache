<?php

declare(strict_types=1);

namespace Omegaalfa\Cache;

use Omegaalfa\Cache\Exception\InvalidArgumentException;

final class CacheManager
{
    /** @var array<string,callable():mixed> */
    private array $factories;
    /** @var array<string,SimpleCache> */
    private array $instances = [];

    /** @param array<string,callable():mixed> $stores */
    public function __construct(private readonly string $defaultStore, array $stores)
    {
        if ($defaultStore === '' || !isset($stores[$defaultStore])) {
            throw new InvalidArgumentException('The default store must reference a configured store.');
        }
        $this->factories = $stores;
    }

    public function store(?string $name = null): SimpleCache
    {
        $name ??= $this->defaultStore;
        if (!isset($this->factories[$name])) {
            throw new InvalidArgumentException("Unknown cache store: $name");
        }
        return $this->instances[$name] ??= $this->create($name);
    }

    private function create(string $name): SimpleCache
    {
        $cache = ($this->factories[$name])();
        if (!$cache instanceof SimpleCache) {
            throw new InvalidArgumentException("Store factory $name did not return SimpleCache.");
        }
        return $cache;
    }
}
