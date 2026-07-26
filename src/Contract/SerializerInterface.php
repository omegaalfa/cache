<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Contract;

interface SerializerInterface
{
    /**
     * @param mixed $value
     * @return string
     */
    public function serialize(mixed $value): string;

    /**
     * @param string $payload
     * @return mixed
     */
    public function unserialize(string $payload): mixed;
}
