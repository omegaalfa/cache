<?php

declare(strict_types=1);

namespace Omegaalfa\Cache;

use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Store\ApcuStore;
use Omegaalfa\Cache\Store\ArrayStore;
use Omegaalfa\Cache\Store\FileStore;
use Omegaalfa\Cache\Store\NullStore;
use Omegaalfa\Cache\Store\RedisStore;

final class CacheFactory
{
    /**
     * @param int|null $maxEntries
     * @return SimpleCache
     */
    public static function array(?int $maxEntries = null): SimpleCache
    {
        return new SimpleCache(new ArrayStore(maxEntries: $maxEntries));
    }

    /**
     * @param FileConfig $config
     * @return SimpleCache
     */
    public static function file(FileConfig $config): SimpleCache
    {
        return new SimpleCache(new FileStore($config));
    }

    /**
     * @param RedisConfig $config
     * @return SimpleCache
     */
    public static function redis(RedisConfig $config): SimpleCache
    {
        return new SimpleCache(new RedisStore($config));
    }

    /**
     * @param \Redis $redis
     * @param string $prefix
     * @return SimpleCache
     */
    public static function fromRedis(\Redis $redis, string $prefix = 'omegaalfa:'): SimpleCache
    {
        return new SimpleCache(new RedisStore(new RedisConfig(prefix: $prefix), $redis));
    }

    /**
     * @param string $prefix
     * @return SimpleCache
     */
    public static function apcu(string $prefix = 'omegaalfa:'): SimpleCache
    {
        return new SimpleCache(new ApcuStore($prefix));
    }

    /**
     * @return SimpleCache
     */
    public static function null(): SimpleCache
    {
        return new SimpleCache(new NullStore());
    }

    /** @param array<string, mixed> $config */
    public static function create(array $config): SimpleCache
    {
        $driver = $config['driver'] ?? null;

        if (!is_string($driver)) {
            throw new InvalidArgumentException(
                'Configuration must explicitly contain a string "driver".',
            );
        }

        return match ($driver) {
            'array' => self::array(self::optionalInt($config, 'max_entries')),
            'null' => self::null(),
            'apcu' => self::apcu(self::optionalString($config, 'prefix') ?? 'omegaalfa:'),
            'file' => self::file(self::fileConfig(self::named($config['file'] ?? []))),
            'redis' => self::redis(self::redisConfig(self::named($config['redis'] ?? []))),
            default => throw new InvalidArgumentException("Unknown cache driver: {$driver}"),
        };
    }

    /** @param array<string, mixed> $config */
    private static function fileConfig(array $config): FileConfig
    {
        return new FileConfig(
            directory: self::requiredString($config, 'directory'),
            directoryMode: self::optionalInt($config, 'directoryMode') ?? 0770,
            fileMode: self::optionalInt($config, 'fileMode') ?? 0660,
            gcProbability: self::optionalInt($config, 'gcProbability') ?? 1,
            gcDivisor: self::optionalInt($config, 'gcDivisor') ?? 100,
        );
    }

    /** @param array<string, mixed> $config */
    private static function redisConfig(array $config): RedisConfig
    {
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('Redis "options" must be an array.');
        }

        /** @var array<int, mixed> $options */
        return new RedisConfig(
            host: self::optionalString($config, 'host') ?? '127.0.0.1',
            port: self::optionalInt($config, 'port') ?? 6379,
            password: self::optionalString($config, 'password'),
            database: self::optionalInt($config, 'database') ?? 0,
            timeout: self::optionalFloat($config, 'timeout') ?? 2.0,
            readTimeout: self::optionalFloat($config, 'readTimeout') ?? 2.0,
            persistent: self::optionalBool($config, 'persistent') ?? false,
            persistentId: self::optionalString($config, 'persistentId'),
            prefix: self::optionalString($config, 'prefix') ?? 'omegaalfa:',
            tls: self::optionalBool($config, 'tls') ?? false,
            options: $options,
        );
    }

    /** @return array<string, mixed> */
    private static function named(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Driver configuration must be an array.');
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Driver configuration keys must be strings.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function requiredString(array $config, string $key): string
    {
        $value = self::optionalString($config, $key);
        if ($value === null) {
            throw new InvalidArgumentException("Configuration key \"{$key}\" is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException("Configuration key \"{$key}\" must be a string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function optionalInt(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidArgumentException("Configuration key \"{$key}\" must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function optionalFloat(array $config, string $key): ?float
    {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException("Configuration key \"{$key}\" must be numeric.");
        }

        return $value === null ? null : (float) $value;
    }

    /** @param array<string, mixed> $config */
    private static function optionalBool(array $config, string $key): ?bool
    {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_bool($value)) {
            throw new InvalidArgumentException("Configuration key \"{$key}\" must be a boolean.");
        }

        return $value;
    }
}
