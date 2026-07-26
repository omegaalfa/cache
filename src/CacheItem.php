<?php

declare(strict_types=1);

namespace Omegaalfa\Cache;

use DateInterval;
use DateTimeInterface;
use Omegaalfa\Cache\Internal\Expiration;
use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface;

final class CacheItem implements CacheItemInterface
{
    /**
     * @var mixed
     */
    private mixed $value;
    /**
     * @var int|null
     */
    private ?int $expiresAt;

    /**
     * @param string $key
     * @param mixed $value
     * @param bool $hit
     * @param int|null $expiresAt
     * @param ClockInterface $clock
     */
    public function __construct(private readonly string $key, mixed $value, private readonly bool $hit, ?int $expiresAt, private readonly ClockInterface $clock)
    {
        $this->value = $value;
        $this->expiresAt = $expiresAt;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return mixed
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isHit(): bool
    {
        return $this->hit && ($this->expiresAt === null || $this->expiresAt > $this->clock->now()->getTimestamp());
    }

    /**
     * @param mixed $value
     * @return $this
     */
    public function set(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @param DateTimeInterface|null $expiration
     * @return $this
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();
        return $this;
    }

    /**
     * @param int|DateInterval|null $time
     * @return $this
     */
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $this->expiresAt = Expiration::after($time, $this->clock->now());
        return $this;
    }

    /**
     * @return int|null
     */
    public function expiration(): ?int
    {
        return $this->expiresAt;
    }
}
