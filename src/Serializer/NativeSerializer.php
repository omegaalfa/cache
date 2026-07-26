<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Serializer;

use ErrorException;
use Omegaalfa\Cache\Contract\SerializerInterface;
use Omegaalfa\Cache\Exception\SerializationException;
use Throwable;

final readonly class NativeSerializer implements SerializerInterface
{
    /**
     * @param bool $allowClasses
     */
    public function __construct(private bool $allowClasses = true) {}

    /**
     * @param mixed $value
     * @return string
     */
    public function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (Throwable $exception) {
            throw new SerializationException('Native serialization failed.', 0, $exception);
        }
    }

    /**
     * @param string $payload
     * @return mixed
     * @throws ErrorException
     */
    public function unserialize(string $payload): mixed
    {
        set_error_handler(
            static function (int $severity, string $message): never {
                throw new ErrorException($message, 0, $severity);
            },
        );

        try {
            return unserialize($payload, ['allowed_classes' => $this->allowClasses]);
        } catch (Throwable $exception) {
            throw new SerializationException('Native unserialization failed.', 0, $exception);
        } finally {
            restore_error_handler();
        }
    }
}
