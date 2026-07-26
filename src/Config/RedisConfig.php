<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Config;

final readonly class RedisConfig
{
    /** @param array<int,mixed> $options */
    public function __construct(public string $host = '127.0.0.1', public int $port = 6379, public ?string $password = null, public int $database = 0, public float $timeout = 2.0, public float $readTimeout = 2.0, public bool $persistent = false, public ?string $persistentId = null, public string $prefix = 'omegaalfa:', public bool $tls = false, public array $options = [])
    {
        if ($host === '' || $port < 1 || $port > 65535 || $database < 0 || $timeout < 0 || $readTimeout < 0 || $prefix === '') {
            throw new \InvalidArgumentException('Invalid Redis configuration.');
        }
    }
}
