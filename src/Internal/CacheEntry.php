<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Internal;

final readonly class CacheEntry
{
    /**
     * @param string $payload
     * @param int|null $expiresAt
     * @param int $createdAt
     */
    public function __construct(public string $payload, public ?int $expiresAt, public int $createdAt) {}

    /**
     * @param int $now
     * @return bool
     */
    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
