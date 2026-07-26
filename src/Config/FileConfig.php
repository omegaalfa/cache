<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Config;

final readonly class FileConfig
{
    /**
     * @param string $directory
     * @param int $directoryMode
     * @param int $fileMode
     * @param int $gcProbability
     * @param int $gcDivisor
     */
    public function __construct(public string $directory, public int $directoryMode = 0770, public int $fileMode = 0660, public int $gcProbability = 1, public int $gcDivisor = 100)
    {
        if ($directory === '' || $gcProbability < 0 || $gcDivisor < 1 || $gcProbability > $gcDivisor) {
            throw new \InvalidArgumentException('Invalid file cache configuration.');
        }
    }
}
