<?php

declare(strict_types=1);

use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Store\FileStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (count($argv) !== 6) {
    fwrite(STDERR, "Expected: operation directory key worker iterations\n");
    exit(2);
}

[, $operation, $directory, $key, $worker, $rawIterations] = $argv;
if (!ctype_digit($rawIterations)) {
    fwrite(STDERR, "Iterations must be numeric.\n");
    exit(2);
}

$iterations = (int) $rawIterations;
$cache = new SimpleCache(new FileStore(new FileConfig($directory, gcProbability: 0)));

try {
    for ($iteration = 0; $iteration < $iterations; ++$iteration) {
        switch ($operation) {
            case 'write':
                $cache->set(
                    $key,
                    "writer-{$worker}-{$iteration}|" . str_repeat('x', 4_096) . '|END',
                );
                break;

            case 'read':
                $value = $cache->get($key);
                if (
                    $value !== null
                    && (!is_string($value) || preg_match('/^writer-\d+-\d+\|x{4096}\|END$/D', $value) !== 1)
                ) {
                    throw new RuntimeException('Reader observed a partial or malformed value.');
                }
                break;

            case 'delete':
                $cache->delete($key);
                break;

            case 'clear':
                $cache->clear();
                break;

            case 'ttl-write':
                $cache->set($key, "writer-{$worker}-{$iteration}|" . str_repeat('x', 4_096) . '|END', 1);
                break;

            default:
                throw new InvalidArgumentException("Unknown operation: {$operation}");
        }

        usleep(1_000);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
