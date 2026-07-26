<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Internal;

use DateInterval;
use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Contract\SerializerInterface;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Serializer\NativeSerializer;
use Psr\Clock\ClockInterface;

final readonly class Engine
{
    /**
     * @param StoreInterface $store
     * @param SerializerInterface $serializer
     * @param ClockInterface $clock
     */
    public function __construct(public StoreInterface $store, public SerializerInterface $serializer = new NativeSerializer(), public ClockInterface $clock = new SystemClock()) {}

    /** @return array{bool, mixed} */
    public function read(string $key): array
    {
        KeyValidator::validate($key);
        $entry = $this->store->get($key);
        return $entry === null ? [false, null] : [true, $this->serializer->unserialize($entry->payload)];
    }

    /**
     * @param mixed $value
     * @param int|DateInterval|null $ttl
     * @return CacheEntry
     */
    public function entry(mixed $value, int|DateInterval|null $ttl): CacheEntry
    {
        $now = $this->clock->now();
        return new CacheEntry($this->serializer->serialize($value), Expiration::after($ttl, $now), $now->getTimestamp());
    }
}
