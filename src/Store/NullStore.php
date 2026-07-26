<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Store;

use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Internal\CacheEntry;

final class NullStore implements StoreInterface
{
    /**
     * @param string $key
     * @return CacheEntry|null
     */
    public function get(string $key): ?CacheEntry
    {
        return null;
    }

    /** @return array<string, CacheEntry> */
    public function getMultiple(array $keys): array
    {
        return [];
    }

    /**
     * @param string $key
     * @param CacheEntry $entry
     * @return bool
     */
    public function set(string $key, CacheEntry $entry): bool
    {
        return true;
    }

    /**
     * @param array<string, CacheEntry> $entries
     * @return bool
     */
    public function setMultiple(array $entries): bool
    {
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return true;
    }

    /**
     * @param list<string> $keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        return true;
    }
}
