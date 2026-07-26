<?php

declare(strict_types=1);

use Omegaalfa\Cache\CacheFactory;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Config\RedisConfig;
use Omegaalfa\Cache\SimpleCache;

require dirname(__DIR__) . '/vendor/autoload.php';

$rounds = positiveEnvironmentInteger('BENCHMARK_ROUNDS', 5);
$memoryIterations = positiveEnvironmentInteger('BENCHMARK_ITERATIONS', 10_000);
$ioIterations = positiveEnvironmentInteger('BENCHMARK_IO_ITERATIONS', 500);
$warmUpIterations = positiveEnvironmentInteger('BENCHMARK_WARMUP', 500);

$array = CacheFactory::array();
$array->set('hit', 'value');
$array->set('ttl-hit', 'value', 3_600);
$array->set('ttl-expired', 'value', 0);

$directory = sys_get_temp_dir() . '/omega-cache-benchmark-' . bin2hex(random_bytes(6));
$file = CacheFactory::file(new FileConfig($directory, gcProbability: 0));
$file->set('hit', 'value');

$scenarios = [
    scenario('array', 'array_set', $memoryIterations, static fn(int $iteration) => $array->set('set', $iteration)),
    scenario('array', 'array_get_hit', $memoryIterations, static fn(int $iteration) => $array->get('hit')),
    scenario('array', 'array_get_miss', $memoryIterations, static fn(int $iteration) => $array->get('missing')),
    scenario('array', 'array_overwrite', $memoryIterations, static fn(int $iteration) => $array->set('overwrite', $iteration)),
    scenario('array', 'array_delete', $memoryIterations, static function (int $iteration) use ($array): void {
        $array->set('delete', $iteration);
        $array->delete('delete');
    }),
    scenario('array', 'array_ttl_hit', $memoryIterations, static fn(int $iteration) => $array->get('ttl-hit')),
    scenario('array', 'array_ttl_expired', $memoryIterations, static fn(int $iteration) => $array->get('ttl-expired')),
    scenario('filesystem', 'filesystem_set', $ioIterations, static fn(int $iteration) => $file->set('set', $iteration)),
    scenario('filesystem', 'filesystem_get_hit', $ioIterations, static fn(int $iteration) => $file->get('hit')),
    scenario('filesystem', 'filesystem_get_miss', $ioIterations, static fn(int $iteration) => $file->get('missing')),
    scenario('filesystem', 'filesystem_delete', $ioIterations, static function (int $iteration) use ($file): void {
        $file->set('delete', $iteration);
        $file->delete('delete');
    }),
];

if (extension_loaded('redis')) {
    try {
        $redis = CacheFactory::redis(new RedisConfig(
            host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('REDIS_PORT') ?: 6379),
            timeout: 0.2,
            readTimeout: 0.2,
            prefix: 'omega-benchmark:' . bin2hex(random_bytes(6)) . ':',
        ));
        $redis->set('hit', 'value', 300);

        $scenarios[] = scenario('redis', 'redis_set', $ioIterations, static fn(int $iteration) => $redis->set('set', $iteration, 300));
        $scenarios[] = scenario('redis', 'redis_get_hit', $ioIterations, static fn(int $iteration) => $redis->get('hit'));
        $scenarios[] = scenario('redis', 'redis_get_miss', $ioIterations, static fn(int $iteration) => $redis->get('missing'));
        $scenarios[] = scenario('redis', 'redis_delete', $ioIterations, static function (int $iteration) use ($redis): void {
            $redis->set('delete', $iteration, 300);
            $redis->delete('delete');
        });
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Redis benchmarks skipped: ' . $exception->getMessage() . PHP_EOL);
    }
}

$environment = [
    'php' => PHP_VERSION,
    'os' => php_uname(),
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'xdebug_loaded' => extension_loaded('xdebug'),
    'rounds' => $rounds,
    'warmup_iterations' => $warmUpIterations,
    'peak_memory_bytes' => memory_get_peak_usage(true),
];

$results = [];
foreach ($scenarios as $scenario) {
    $operation = $scenario['operation'];
    for ($iteration = 0; $iteration < min($warmUpIterations, $scenario['iterations']); ++$iteration) {
        $operation($iteration);
    }

    $samples = [];
    for ($round = 0; $round < $rounds; ++$round) {
        $startedAt = hrtime(true);
        for ($iteration = 0; $iteration < $scenario['iterations']; ++$iteration) {
            $operation($iteration);
        }
        $samples[] = (hrtime(true) - $startedAt) / $scenario['iterations'];
    }

    sort($samples, SORT_NUMERIC);
    $mean = array_sum($samples) / count($samples);
    $median = percentile($samples, 0.5);
    $variance = array_sum(array_map(
        static fn(float $sample): float => ($sample - $mean) ** 2,
        $samples,
    )) / count($samples);

    $results[] = [
        'backend' => $scenario['backend'],
        'scenario' => $scenario['name'],
        'iterations_per_round' => $scenario['iterations'],
        'mean_ns' => round($mean, 2),
        'median_ns' => round($median, 2),
        'min_ns' => round($samples[0], 2),
        'max_ns' => round($samples[count($samples) - 1], 2),
        'stddev_ns' => round(sqrt($variance), 2),
        'operations_per_second' => round(1_000_000_000 / $mean, 2),
    ];
}

$environment['peak_memory_bytes'] = memory_get_peak_usage(true);

echo json_encode(
    ['environment' => $environment, 'results' => $results],
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
), PHP_EOL;

removeDirectory($directory);

/**
 * @param Closure(int): mixed $operation
 *
 * @return array{backend: string, name: string, iterations: int, operation: Closure(int): mixed}
 */
function scenario(string $backend, string $name, int $iterations, Closure $operation): array
{
    return compact('backend', 'name', 'iterations', 'operation');
}

/** @param list<float> $samples */
function percentile(array $samples, float $percentile): float
{
    $position = (count($samples) - 1) * $percentile;
    $lower = (int) floor($position);
    $upper = (int) ceil($position);

    if ($lower === $upper) {
        return $samples[$lower];
    }

    return $samples[$lower] + ($samples[$upper] - $samples[$lower]) * ($position - $lower);
}

function positiveEnvironmentInteger(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    if (!ctype_digit($value) || (int) $value < 1) {
        throw new InvalidArgumentException("{$name} must be a positive integer.");
    }

    return (int) $value;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
