<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Store;

use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Internal\CacheEntry;
use Psr\Clock\ClockInterface;
use Omegaalfa\Cache\Clock\SystemClock;

final class ArrayStore implements StoreInterface
{
    /** @var array<string, CacheEntry> */
    private array $entries = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock(), private readonly ?int $maxEntries = null)
    {
        if ($maxEntries !== null && $maxEntries < 1) {
            throw new \InvalidArgumentException('maxEntries must be positive.');
        }
    }

    public function get(string $key): ?CacheEntry
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry?->isExpired($this->clock->now()->getTimestamp())) {
            unset($this->entries[$key]);
            return null;
        }
        return $entry;
    }

    /** @return array<string, CacheEntry> */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (($entry = $this->get($key)) !== null) {
                $result[$key] = $entry;
            }
        }
        return $result;
    }

    public function set(string $key, CacheEntry $entry): bool
    {
        if ($entry->isExpired($this->clock->now()->getTimestamp())) {
            return $this->delete($key);
        }
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
        if ($this->maxEntries !== null && count($this->entries) > $this->maxEntries) {
            array_shift($this->entries);
        }
        return true;
    }

    public function setMultiple(array $entries): bool
    {
        foreach ($entries as $key => $entry) {
            $this->set($key, $entry);
        }
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->entries[$key]);
        }
        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];
        return true;
    }
}
