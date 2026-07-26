<?php

declare(strict_types=1);

namespace Omegaalfa\Cache;

use DateInterval;
use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Contract\SerializerInterface;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Internal\Engine;
use Omegaalfa\Cache\Internal\KeyValidator;
use Omegaalfa\Cache\Serializer\NativeSerializer;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class SimpleCache implements CacheInterface
{
    /**
     * @var Engine
     */
    private Engine $engine;

    /**
     * @param StoreInterface $store
     * @param SerializerInterface|null $serializer
     * @param ClockInterface|null $clock
     */
    public function __construct(
        StoreInterface       $store,
        ?SerializerInterface $serializer = null,
        ?ClockInterface      $clock = null,
    ) {
        $this->engine = new Engine(
            $store,
            $serializer ?? new NativeSerializer(),
            $clock ?? new SystemClock(),
        );
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$hit, $value] = $this->engine->read($key);

        return $hit ? $value : $default;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|DateInterval|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::validate($key);

        return $this->engine->store->set($key, $this->engine->entry($value, $ttl));
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        KeyValidator::validate($key);

        return $this->engine->store->delete($key);
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        return $this->engine->store->clear();
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = $this->keys($keys);
        $entries = $this->engine->store->getMultiple($list);
        $result = [];

        foreach ($list as $key) {
            $entry = $entries[$key] ?? null;
            $result[$key] = $entry === null
                ? $default
                : $this->engine->serializer->unserialize($entry->payload);
        }

        return $result;
    }

    /** @param iterable<mixed, mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $entries = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache keys must be strings.');
            }

            KeyValidator::validate($key);
            $entries[$key] = $this->engine->entry($value, $ttl);
        }

        return $this->engine->store->setMultiple($entries);
    }

    /** @param iterable<mixed> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->engine->store->deleteMultiple($this->keys($keys));
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        [$hit] = $this->engine->read($key);

        return $hit;
    }

    /**
     * @param string $key
     * @param int|DateInterval|null $ttl
     * @param callable $resolver
     * @return mixed
     */
    public function remember(string $key, null|int|DateInterval $ttl, callable $resolver): mixed
    {
        [$hit, $value] = $this->engine->read($key);

        if ($hit) {
            return $value;
        }

        $value = $resolver();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     */
    private function keys(iterable $keys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache keys must be strings.');
            }

            KeyValidator::validate($key);
            $validated[] = $key;
        }

        return $validated;
    }
}
