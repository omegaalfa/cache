<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable$now) {}public function now(): DateTimeImmutable
    {
        return$this->now;
    }public function advance(string$modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
