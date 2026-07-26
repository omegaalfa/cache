<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Internal;

use Omegaalfa\Cache\Exception\InvalidKeyException;

final class KeyValidator
{
    /**
     * @param string $key
     * @return void
     */
    public static function validate(string $key): void
    {
        if ($key === '') {
            throw new InvalidKeyException('Cache key must not be empty.');
        }
        if (preg_match('#[{}()/\\\\@:]+#', $key) === 1) {
            throw new InvalidKeyException(sprintf('Cache key "%s" contains a reserved character.', $key));
        }
    }
}
