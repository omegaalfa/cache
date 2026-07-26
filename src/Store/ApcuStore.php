<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Store;

use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Exception\BackendUnavailableException;
use Omegaalfa\Cache\Exception\CorruptedCacheEntryException;
use Omegaalfa\Cache\Internal\CacheEntry;
use Psr\Clock\ClockInterface;

final class ApcuStore implements StoreInterface
{
    private int $version;

    /**
     * @param string $prefix
     * @param ClockInterface $clock
     */
    public function __construct(
        private readonly string         $prefix = 'omegaalfa:',
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if (!extension_loaded('apcu') || !filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            throw new BackendUnavailableException(
                'ApcuStore requires enabled ext-apcu. For CLI, enable apc.enable_cli=1.',
            );
        }

        $value = apcu_fetch($this->versionKey(), $success);
        if ($success && !is_int($value)) {
            throw new CorruptedCacheEntryException('Malformed APCu namespace version.');
        }

        $this->version = $success ? $value : 1;
        if (!$success && !apcu_add($this->versionKey(), 1)) {
            $value = apcu_fetch($this->versionKey(), $success);
            if (!$success || !is_int($value)) {
                throw new BackendUnavailableException('Unable to initialize APCu namespace version.');
            }
            $this->version = $value;
        }
    }

    /**
     * @param string $key
     * @return CacheEntry|null
     */
    public function get(string $key): ?CacheEntry
    {
        $value = apcu_fetch($this->key($key), $success);
        if (!$success) {
            return null;
        }
        if (!$value instanceof CacheEntry) {
            throw new CorruptedCacheEntryException('Malformed APCu cache entry.');
        }
        if ($value->isExpired($this->clock->now()->getTimestamp())) {
            $this->delete($key);

            return null;
        }

        return $value;
    }

    /** @return array<string, CacheEntry> */
    public function getMultiple(array $keys): array
    {
        $map = [];
        foreach ($keys as $key) {
            $map[$this->key($key)] = $key;
        }

        $values = apcu_fetch(array_keys($map));
        if (!is_array($values)) {
            throw new BackendUnavailableException('APCu bulk fetch returned an invalid response.');
        }

        $result = [];
        foreach ($values as $physical => $value) {
            if (!is_string($physical) || !isset($map[$physical])) {
                throw new CorruptedCacheEntryException('Malformed APCu bulk response.');
            }
            if (!$value instanceof CacheEntry) {
                throw new CorruptedCacheEntryException('Malformed APCu cache entry.');
            }
            if (!$value->isExpired($this->clock->now()->getTimestamp())) {
                $result[$map[$physical]] = $value;
            }
        }

        return $result;
    }

    /**
     * @param string $key
     * @param CacheEntry $entry
     * @return bool
     */
    public function set(string $key, CacheEntry $entry): bool
    {
        $ttl = $entry->expiresAt === null
            ? 0
            : $entry->expiresAt - $this->clock->now()->getTimestamp();

        if ($ttl <= 0 && $entry->expiresAt !== null) {
            return $this->delete($key);
        }

        return apcu_store($this->key($key), $entry, $ttl);
    }

    /**
     * @param array<string, CacheEntry> $entries
     * @return bool
     */
    public function setMultiple(array $entries): bool
    {
        $success = true;
        foreach ($entries as $key => $entry) {
            $success = $this->set($key, $entry) && $success;
        }

        return $success;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return apcu_delete($this->key($key)) || !apcu_exists($this->key($key));
    }

    /**
     * @param list<string> $keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $value = apcu_inc($this->versionKey(), 1, $success);
        if (!$success || !is_int($value)) {
            throw new BackendUnavailableException('Unable to increment APCu namespace version.');
        }

        $this->version = $value;

        return true;
    }

    /**
     * @param string $key
     * @return string
     */
    private function key(string $key): string
    {
        return $this->prefix . 'v' . $this->version . ':' . $key;
    }

    /**
     * @return string
     */
    private function versionKey(): string
    {
        return $this->prefix . '__namespace_version';
    }
}
