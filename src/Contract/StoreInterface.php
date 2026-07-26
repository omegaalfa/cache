<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Contract;

use Omegaalfa\Cache\Internal\CacheEntry;

interface StoreInterface
{
    /**
     * @param string $key
     * @return CacheEntry|null
     */
    public function get(string $key): ?CacheEntry;

    /**
     * @param list<string> $keys
     * @return array<string, CacheEntry>
     */
    public function getMultiple(array $keys): array;

    /**
     * @param string $key
     * @param CacheEntry $entry
     * @return bool
     */
    public function set(string $key, CacheEntry $entry): bool;

    /** @param array<string, CacheEntry> $entries */
    public function setMultiple(array $entries): bool;

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /** @param list<string> $keys */
    public function deleteMultiple(array $keys): bool;

    /**
     * @return bool
     */
    public function clear(): bool;
}
