<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Kgkg\SentryDedupe\{Config, Factory};
use Kgkg\SentryDedupe\Tests\Fixtures\FakeClock;

[$script, $file, $barrier, $index, $workload, $samples] = $argv;
$c = Config::fromArray(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
$s = Factory::store($c, new FakeClock());
file_put_contents($barrier . '.' . $index . '.ready', 'ready');
$deadline = hrtime(true) + 60000000000;
while (!file_exists($barrier)) {
    clearstatcache(true, $barrier);
    if (hrtime(true) >= $deadline) { exit(1); }
    usleep(1000);
}
$timings = []; $failures = 0; $timeouts = 0; $categories = [];
$cpuStart = function_exists('getrusage') ? getrusage() : null;
for ($i = 0; $i < (int) $samples; ++$i) {
    $start = hrtime(true);
    try { $s->observe(hash('sha256', $workload === 'repeated' ? 'preload:0' : 'new:' . $index . ':' . $i)); }
    catch (Throwable $e) {
        ++$failures;
        $category = $e instanceof \Kgkg\SentryDedupe\Exception\StorageException ? $e->getMessage() : 'unexpected';
        $categories[$category] = ($categories[$category] ?? 0) + 1;
        if (in_array($category, ['lock_timeout', 'sqlite_busy_locked'], true)) { ++$timeouts; }
    }
    $timings[] = (hrtime(true) - $start) / 1000000;
}
$cpuEnd = function_exists('getrusage') ? getrusage() : null;
$cpu = null;
if (is_array($cpuStart) && is_array($cpuEnd)) {
    $cpu = ($cpuEnd['ru_utime.tv_sec'] + $cpuEnd['ru_stime.tv_sec'] - $cpuStart['ru_utime.tv_sec'] - $cpuStart['ru_stime.tv_sec']) * 1000
        + ($cpuEnd['ru_utime.tv_usec'] + $cpuEnd['ru_stime.tv_usec'] - $cpuStart['ru_utime.tv_usec'] - $cpuStart['ru_stime.tv_usec']) / 1000;
}
echo json_encode(['timings_ms' => $timings, 'storage_failures' => $failures, 'timeouts' => $timeouts, 'failure_categories' => $categories,
    'peak_worker_bytes' => memory_get_peak_usage(true), 'cpu_ms' => $cpu], JSON_THROW_ON_ERROR);
