<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Store;

use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Exception\CorruptedCacheEntryException;
use Omegaalfa\Cache\Exception\RedisConnectionException;
use Omegaalfa\Cache\Internal\CacheEntry;
use Psr\Clock\ClockInterface;

final class RedisStore implements StoreInterface
{
    /**
     * @var \Redis|null
     */
    private ?\Redis $redis;
    /**
     * @var int
     */
    private int $version = 0;

    /**
     * @param RedisConfig|null $config
     * @param \Redis|null $redis
     * @param ClockInterface $clock
     */
    public function __construct(
        private readonly ?RedisConfig   $config = null,
        ?\Redis                         $redis = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if (!extension_loaded('redis')) {
            throw new RedisConnectionException('RedisStore requires ext-redis.');
        }
        if ($config === null && $redis === null) {
            throw new \InvalidArgumentException(
                'RedisStore requires RedisConfig or an external Redis instance.',
            );
        }

        $this->redis = $redis;
    }

    /**
     * @param string $key
     * @return CacheEntry|null
     */
    public function get(string $key): ?CacheEntry
    {
        $raw = $this->call(fn(\Redis $redis): mixed => $redis->get($this->key($key)));
        if ($raw === false) {
            return null;
        }
        if (!is_string($raw)) {
            throw new RedisConnectionException('Redis GET returned an invalid response.');
        }

        $entry = $this->decode($raw);
        if ($entry->isExpired($this->clock->now()->getTimestamp())) {
            $this->delete($key);

            return null;
        }

        return $entry;
    }

    /** @return array<string, CacheEntry> */
    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $physicalKeys = array_map(
            fn(string $key): string => $this->key($key),
            $keys,
        );
        $values = $this->call(
            fn(\Redis $redis): mixed => $redis->mGet($physicalKeys),
        );
        if (!is_array($values)) {
            throw new RedisConnectionException('Redis MGET returned an invalid response.');
        }

        $result = [];
        foreach ($keys as $index => $key) {
            $value = $values[$index] ?? false;
            if ($value === false) {
                continue;
            }
            if (!is_string($value)) {
                throw new RedisConnectionException('Redis MGET returned a malformed value.');
            }

            $entry = $this->decode($value);
            if (!$entry->isExpired($this->clock->now()->getTimestamp())) {
                $result[$key] = $entry;
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
        $ttl = $this->ttl($entry);
        if ($ttl !== null && $ttl <= 0) {
            return $this->delete($key);
        }

        $response = $this->call(
            fn(\Redis $redis): mixed => $ttl === null
                ? $redis->set($this->key($key), $this->encode($entry))
                : $redis->setEx($this->key($key), $ttl, $this->encode($entry)),
        );
        if ($response !== true) {
            throw new RedisConnectionException('Redis SET failed.');
        }

        return true;
    }

    /**
     * @param array<string, CacheEntry> $entries
     * @return bool
     * @throws \JsonException
     */
    public function setMultiple(array $entries): bool
    {
        if ($entries === []) {
            return true;
        }

        $redis = $this->client();
        $this->version();

        try {
            $pipeline = $redis->multi(\Redis::PIPELINE);
            foreach ($entries as $key => $entry) {
                $ttl = $this->ttl($entry);
                if ($ttl !== null && $ttl <= 0) {
                    $pipeline->del($this->key($key));
                } elseif ($ttl === null) {
                    $pipeline->set($this->key($key), $this->encode($entry));
                } else {
                    $pipeline->setEx($this->key($key), $ttl, $this->encode($entry));
                }
            }

            $responses = $pipeline->exec();
            if (!is_array($responses) || in_array(false, $responses, true)) {
                throw new RedisConnectionException('Redis pipeline had a partial failure.');
            }

            return true;
        } catch (\RedisException $exception) {
            throw new RedisConnectionException(
                'Redis pipeline failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $response = $this->call(
            fn(\Redis $redis): mixed => $redis->del($this->key($key)),
        );

        if (!is_int($response)) {
            throw new RedisConnectionException('Redis DEL returned an invalid response.');
        }

        return true;
    }

    /**
     * @param list<string> $keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $physicalKeys = array_map(
            fn(string $key): string => $this->key($key),
            $keys,
        );
        $response = $this->call(
            fn(\Redis $redis): mixed => $redis->del($physicalKeys),
        );

        if (!is_int($response)) {
            throw new RedisConnectionException('Redis DEL returned an invalid response.');
        }

        return true;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        $this->version();
        $response = $this->call(
            fn(\Redis $redis): mixed => $redis->incr($this->versionKey()),
        );
        if (!is_int($response) || $response < 1) {
            throw new RedisConnectionException(
                'Redis namespace increment returned an invalid response.',
            );
        }

        $this->version = $response;

        return true;
    }

    /**
     * @param CacheEntry $entry
     * @return int|null
     */
    private function ttl(CacheEntry $entry): ?int
    {
        return $entry->expiresAt === null
            ? null
            : $entry->expiresAt - $this->clock->now()->getTimestamp();
    }

    /**
     * @return \Redis
     */
    private function client(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $config = $this->config;
        if ($config === null) {
            throw new RedisConnectionException('Redis connection configuration is missing.');
        }

        $redis = new \Redis();

        try {
            $host = $config->tls ? 'tls://' . $config->host : $config->host;
            $connected = $config->persistent
                ? $redis->pconnect(
                    $host,
                    $config->port,
                    $config->timeout,
                    $config->persistentId ?? '',
                    0,
                    $config->readTimeout,
                )
                : $redis->connect(
                    $host,
                    $config->port,
                    $config->timeout,
                    null,
                    0,
                    $config->readTimeout,
                );

            if (!$connected) {
                throw new \RedisException('connect returned false');
            }
            if ($config->password !== null && !$redis->auth($config->password)) {
                throw new \RedisException('authentication failed');
            }
            if ($config->database !== 0 && !$redis->select($config->database)) {
                throw new \RedisException('database selection failed');
            }
            foreach ($config->options as $option => $value) {
                if (!$redis->setOption($option, $value)) {
                    throw new \RedisException("option {$option} was rejected");
                }
            }

            $this->redis = $redis;

            return $redis;
        } catch (\RedisException $exception) {
            throw new RedisConnectionException(
                'Unable to initialize Redis: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private function key(string $key): string
    {
        return $this->prefix() . 'v' . $this->version() . ':' . $key;
    }

    /**
     * @return int
     */
    private function version(): int
    {
        if ($this->version > 0) {
            return $this->version;
        }

        $response = $this->call(
            fn(\Redis $redis): mixed => $redis->get($this->versionKey()),
        );
        if ($response === false) {
            $this->call(
                fn(\Redis $redis): mixed => $redis->setNx($this->versionKey(), 1),
            );
            $response = $this->call(
                fn(\Redis $redis): mixed => $redis->get($this->versionKey()),
            );
        }

        if (is_int($response)) {
            $version = $response;
        } elseif (is_string($response) && ctype_digit($response)) {
            $version = (int) $response;
        } else {
            throw new RedisConnectionException(
                'Redis namespace version returned an invalid response.',
            );
        }

        if ($version < 1) {
            throw new RedisConnectionException('Redis namespace version must be positive.');
        }

        return $this->version = $version;
    }

    /**
     * @return string
     */
    private function prefix(): string
    {
        return $this->config === null ? 'omegaalfa:' : $this->config->prefix;
    }

    /**
     * @return string
     */
    private function versionKey(): string
    {
        return $this->prefix() . '__namespace_version';
    }

    /** @param callable(\Redis): mixed $operation */
    private function call(callable $operation): mixed
    {
        try {
            return $operation($this->client());
        } catch (\RedisException $exception) {
            throw new RedisConnectionException(
                'Redis operation failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param CacheEntry $entry
     * @return string
     * @throws \JsonException
     */
    private function encode(CacheEntry $entry): string
    {
        return json_encode(
            [
                'p' => base64_encode($entry->payload),
                'e' => $entry->expiresAt,
                'c' => $entry->createdAt,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param string $raw
     * @return CacheEntry
     */
    private function decode(string $raw): CacheEntry
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (
                !is_array($data)
                || !isset($data['p'])
                || !is_string($data['p'])
                || !array_key_exists('e', $data)
                || !isset($data['c'])
                || !is_int($data['c'])
                || ($data['e'] !== null && !is_int($data['e']))
            ) {
                throw new \UnexpectedValueException();
            }

            $payload = base64_decode($data['p'], true);
            if ($payload === false) {
                throw new \UnexpectedValueException();
            }

            return new CacheEntry($payload, $data['e'], $data['c']);
        } catch (\Throwable $exception) {
            throw new CorruptedCacheEntryException(
                'Malformed Redis cache entry.',
                0,
                $exception,
            );
        }
    }
}
