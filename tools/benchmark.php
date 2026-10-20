<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Kgkg\SentryDedupe\Factory;
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock, Process};

$report = ['php' => PHP_VERSION, 'sdk' => Composer\InstalledVersions::getPrettyVersion('sentry/sentry'),
    'os' => php_uname(), 'sqlite' => extension_loaded('sqlite3') ? SQLite3::version() : null,
    'cpu' => getenv('PROCESSOR_IDENTIFIER') ?: null, 'logical_processors' => getenv('NUMBER_OF_PROCESSORS') ?: null,
    'disk' => getenv('DEDUPE_BENCH_DISK') ?: 'Report filesystem/device separately', 'rows' => []];
try {
    foreach (['json', 'sqlite'] as $backend) {
        foreach ([1, 8, 32] as $workers) {
            foreach (['repeated', 'new'] as $workload) {
                $c = Stores::config($backend, ['global_limit' => ['max_events' => 100000]]);
                $s = Factory::store($c, new FakeClock()); $s->initialize();
                for ($i = 0; $i < 1000; ++$i) { $s->observe(hash('sha256', 'preload:' . $i)); }
                $root = dirname($c->get('sqlite')['path']);
                if (!is_dir($root)) { mkdir($root, 0700, true); }
                $file = $root . '/benchmark.json'; $barrier = $root . '/go';
                file_put_contents($file, json_encode($c->toSafeArray(), JSON_THROW_ON_ERROR));
                $children = [];
                $samples = (int) ceil(512 / $workers);
                for ($i = 0; $i < $workers; ++$i) {
                    $p = proc_open(Process::command([__DIR__ . '/benchmark-worker.php', $file, $barrier, (string) $i, $workload, (string) $samples]),
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                    if (!is_resource($p)) { throw new RuntimeException('spawn failed'); }
                    fclose($pipes[0]); $children[] = [$p, $pipes];
                }
                $deadline = hrtime(true) + 60000000000;
                do {
                    clearstatcache();
                    if (count(glob($barrier . '.*.ready') ?: []) === $workers) { break; }
                    if (hrtime(true) >= $deadline) { throw new RuntimeException('barrier timeout'); }
                    usleep(1000);
                } while (true);
                $start = hrtime(true); file_put_contents($barrier, 'go');
                $times = []; $failures = 0; $peak = 0; $cpu = null; $timeouts = 0; $categories = [];
                foreach ($children as [$p, $pipes]) {
                    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                    fclose($pipes[1]); fclose($pipes[2]);
                    if (proc_close($p) !== 0 || $err !== '') { throw new RuntimeException($err ?: 'worker failed'); }
                    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
                    $times = array_merge($times, $data['timings_ms']); $failures += $data['storage_failures'];
                    $timeouts += $data['timeouts'];
                    foreach ($data['failure_categories'] as $category => $count) { $categories[$category] = ($categories[$category] ?? 0) + $count; }
                    $peak = max($peak, $data['peak_worker_bytes']);
                    if ($data['cpu_ms'] !== null) { $cpu = ($cpu ?? 0) + $data['cpu_ms']; }
                }
                sort($times, SORT_NUMERIC);
                $percentile = static function (float $p) use ($times): float { return round($times[(int) ceil(count($times) * $p) - 1], 3); };
                $row = ['backend' => $backend, 'workers' => $workers, 'workload' => $workload, 'initial_fingerprints' => 1000, 'samples' => count($times),
                    'p50_ms' => $percentile(0.5), 'p95_ms' => $percentile(0.95), 'p99_ms' => $percentile(0.99),
                    'storage_failures' => $failures, 'timeouts' => $timeouts, 'failure_categories' => $categories,
                    'max_worker_peak_bytes' => $peak, 'worker_cpu_ms' => $cpu, 'wall_ms' => round((hrtime(true) - $start) / 1000000, 1)];
                $report['rows'][] = $row;
                echo json_encode($row, JSON_THROW_ON_ERROR) . "\n";
            }
        }
    }
    file_put_contents(dirname(__DIR__) . '/.cache/benchmark-' . PHP_VERSION . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
