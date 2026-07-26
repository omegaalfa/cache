<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Internal;

use DateInterval;
use DateTimeImmutable;
use Omegaalfa\Cache\Exception\InvalidArgumentException;

final class Expiration
{
    /**
     * @param int|DateInterval|null $ttl
     * @param DateTimeImmutable $now
     * @return int|null
     */
    public static function after(int|DateInterval|null $ttl, DateTimeImmutable $now): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if (is_int($ttl)) {
            if ($ttl <= 0) {
                return $now->getTimestamp();
            }
            if ($ttl > PHP_INT_MAX - $now->getTimestamp()) {
                throw new InvalidArgumentException('TTL overflows the supported timestamp range.');
            }
            return $now->getTimestamp() + $ttl;
        }
        try {
            $expires = $now->add($ttl)->getTimestamp();
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Invalid DateInterval TTL.', 0, $e);
        }
        return $expires;
    }
}
