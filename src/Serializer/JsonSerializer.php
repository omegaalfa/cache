<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Serializer;

use Omegaalfa\Cache\Contract\SerializerInterface;
use Omegaalfa\Cache\Exception\SerializationException;

/**
 *
 */
final readonly class JsonSerializer implements SerializerInterface
{
    /**
     * @param mixed $value
     * @return string
     */
    public function serialize(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new SerializationException('JSON encoding failed.', 0, $e);
        }
    }

    /**
     * @param string $payload
     * @return mixed
     */
    public function unserialize(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new SerializationException('JSON decoding failed.', 0, $e);
        }
    }
}
