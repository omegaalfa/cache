<?php

declare(strict_types=1);

namespace Omegaalfa\Cache\Store;

use FilesystemIterator;
use Omegaalfa\Cache\Clock\SystemClock;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Contract\StoreInterface;
use Omegaalfa\Cache\Exception\BackendUnavailableException;
use Omegaalfa\Cache\Exception\CorruptedCacheEntryException;
use Omegaalfa\Cache\Internal\CacheEntry;
use Psr\Clock\ClockInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

final class FileStore implements StoreInterface
{
    /**
     * @var int
     */
    private int $namespaceVersion = 1;

    /**
     * @param FileConfig $config
     * @param ClockInterface $clock
     */
    public function __construct(
        private readonly FileConfig     $config,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->ensureDirectory($config->directory);
        $this->namespaceVersion = $this->readVersion();
    }

    /**
     * @param string $key
     * @return CacheEntry|null
     */
    public function get(string $key): ?CacheEntry
    {
        return $this->withNamespaceLock(LOCK_SH, function () use ($key): ?CacheEntry {
            $path = $this->path($key);
            if (!is_file($path)) {
                return null;
            }

            $raw = @file_get_contents($path);
            if ($raw === false) {
                if (@lstat($path) === false) {
                    return null;
                }

                throw new BackendUnavailableException("Unable to read cache file: {$path}");
            }

            $entry = $this->decode($raw);
            if ($entry->isExpired($this->clock->now()->getTimestamp())) {
                @unlink($path);

                return null;
            }

            return $entry;
        });
    }

    /** @return array<string, CacheEntry> */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $entry = $this->get($key);
            if ($entry !== null) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param string $key
     * @param CacheEntry $entry
     * @return bool
     * @throws \JsonException
     */
    public function set(string $key, CacheEntry $entry): bool
    {
        return $this->withNamespaceLock(LOCK_SH, function () use ($key, $entry): bool {
            if ($entry->isExpired($this->clock->now()->getTimestamp())) {
                return $this->deletePath($key);
            }

            $path = $this->path($key);
            $this->ensureDirectory(dirname($path));
            $temporary = tempnam(dirname($path), '.cache-');
            if ($temporary === false) {
                throw new BackendUnavailableException(
                    'Unable to create a cache temporary file.',
                );
            }

            try {
                $bytes = @file_put_contents($temporary, $this->encode($entry), LOCK_EX);
                if (
                    $bytes === false
                    || !@chmod($temporary, $this->config->fileMode)
                    || !@rename($temporary, $path)
                ) {
                    throw new BackendUnavailableException("Atomic cache write failed: {$path}");
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            $this->maybeGc();

            return true;
        });
    }

    /**
     * @param array<string, CacheEntry> $entries
     * @return bool
     * @throws \JsonException
     */
    public function setMultiple(array $entries): bool
    {
        $success = true;
        foreach ($entries as $key => $entry) {
            $success = $this->set($key, $entry) && $success;
        }

        return $success;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->withNamespaceLock(
            LOCK_SH,
            fn(): bool => $this->deletePath($key),
        );
    }

    /**
     * @param list<string> $keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        return $this->withNamespaceLock(LOCK_EX, function (): bool {
            $this->namespaceVersion = $this->readVersion() + 1;
            $this->atomicWrite(
                $this->config->directory . '/.namespace',
                (string) $this->namespaceVersion,
            );

            return true;
        });
    }

    /**
     * @param string $key
     * @return bool
     */
    private function deletePath(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    /**
     * @param string $key
     * @return string
     */
    private function path(string $key): string
    {
        $hash = hash('sha256', $this->namespaceVersion . "\0" . $key);

        return $this->config->directory
            . '/' . $hash[0] . $hash[1]
            . '/' . $hash[2] . $hash[3]
            . '/' . $hash . '.cache';
    }

    /**
     * @param CacheEntry $entry
     * @return string
     * @throws \JsonException
     */
    private function encode(CacheEntry $entry): string
    {
        $data = [
            'payload' => base64_encode($entry->payload),
            'expiresAt' => $entry->expiresAt,
            'createdAt' => $entry->createdAt,
        ];
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        return hash('sha256', $json) . "\n" . $json;
    }

    /**
     * @param string $raw
     * @return CacheEntry
     */
    private function decode(string $raw): CacheEntry
    {
        [$checksum, $json] = array_pad(explode("\n", $raw, 2), 2, '');
        if (!hash_equals($checksum, hash('sha256', $json))) {
            throw new CorruptedCacheEntryException('Cache file checksum mismatch.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (
                !is_array($data)
                || !isset($data['payload'])
                || !is_string($data['payload'])
                || !array_key_exists('expiresAt', $data)
                || !isset($data['createdAt'])
                || !is_int($data['createdAt'])
                || ($data['expiresAt'] !== null && !is_int($data['expiresAt']))
            ) {
                throw new UnexpectedValueException();
            }

            $payload = base64_decode($data['payload'], true);
            if ($payload === false) {
                throw new UnexpectedValueException();
            }

            return new CacheEntry($payload, $data['expiresAt'], $data['createdAt']);
        } catch (Throwable $exception) {
            throw new CorruptedCacheEntryException(
                'Malformed cache file.',
                0,
                $exception,
            );
        }
    }

    /**
     * @return int
     */
    private function readVersion(): int
    {
        $path = $this->config->directory . '/.namespace';
        $raw = is_file($path) ? @file_get_contents($path) : false;

        return $raw !== false && ctype_digit(trim($raw))
            ? max(1, (int) trim($raw))
            : 1;
    }

    /**
     * @param string $path
     * @param string $value
     * @return void
     */
    private function atomicWrite(string $path, string $value): void
    {
        $temporary = tempnam(dirname($path), '.meta-');
        if (
            $temporary === false
            || file_put_contents($temporary, $value, LOCK_EX) === false
            || !rename($temporary, $path)
        ) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }

            throw new BackendUnavailableException(
                'Unable to write namespace metadata.',
            );
        }
    }

    /**
     * @param string $directory
     * @return void
     */
    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !@mkdir($directory, $this->config->directoryMode, true)
            && !is_dir($directory)
        ) {
            throw new BackendUnavailableException(
                "Unable to create cache directory: {$directory}",
            );
        }
        if (!is_writable($directory)) {
            throw new BackendUnavailableException(
                "Cache directory is not writable: {$directory}",
            );
        }
    }

    /**
     * @return void
     * @throws \Random\RandomException
     */
    private function maybeGc(): void
    {
        if (
            $this->config->gcProbability === 0
            || random_int(1, $this->config->gcDivisor) > $this->config->gcProbability
        ) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->config->directory,
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if (
                !$file instanceof SplFileInfo
                || !$file->isFile()
                || $file->getExtension() !== 'cache'
            ) {
                continue;
            }

            try {
                $raw = @file_get_contents($file->getPathname());
                if ($raw !== false && $this->decode($raw)->isExpired($now)) {
                    @unlink($file->getPathname());
                }
            } catch (CorruptedCacheEntryException) {
                // Retain corrupted entries for explicit diagnosis on access.
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withNamespaceLock(int $mode, callable $operation): mixed
    {
        if ($mode !== LOCK_SH && $mode !== LOCK_EX) {
            throw new \InvalidArgumentException('Unsupported namespace lock mode.');
        }

        $path = $this->config->directory . '/.namespace.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new BackendUnavailableException(
                'Unable to open file cache namespace lock.',
            );
        }

        try {
            if (!flock($handle, $mode)) {
                throw new BackendUnavailableException(
                    'Unable to lock file cache namespace.',
                );
            }

            $this->namespaceVersion = $this->readVersion();

            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
