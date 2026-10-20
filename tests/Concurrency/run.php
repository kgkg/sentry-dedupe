<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kgkg\SentryDedupe\{Factory, StatsQuery};
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock, Process};

$backends = getenv('SENTRY_DEDUPE_BACKEND') ? [getenv('SENTRY_DEDUPE_BACKEND')] : ['json', 'sqlite'];
$repetitions = (int) (getenv('DEDUPE_CONCURRENCY_REPETITIONS') ?: 10);
$report = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sdk' => Composer\InstalledVersions::getPrettyVersion('sentry/sentry'),
    'sqlite' => extension_loaded('sqlite3') ? SQLite3::version() : null, 'runs' => []];
try {
    foreach ($backends as $backend) {
        $modes = getenv('DEDUPE_CONCURRENCY_MODES') ? explode(',', getenv('DEDUPE_CONCURRENCY_MODES')) : array_merge(array_fill(0, $repetitions, 'same'), ['distinct', 'mixed', 'init']);
        foreach ($modes as $iteration => $mode) {
            $c = Stores::config($backend, ['global_limit' => ['max_events' => 10]]);
            $root = dirname($c->get('sqlite')['path']);
            if (!is_dir($root) && !mkdir($root, 0700, true)) { throw new RuntimeException('mkdir'); }
            $file = $root . '/config.json';
            file_put_contents($file, json_encode($c->toSafeArray(), JSON_THROW_ON_ERROR));
            $clock = new FakeClock();
            $store = Factory::store($c, $clock);
            if ($mode !== 'init') { $store->initialize(); }
            $barrier = $root . '/start';
            $children = [];
            $start = hrtime(true);
            for ($i = 0; $i < 32; ++$i) {
                $p = proc_open(Process::command([__DIR__ . '/worker.php', $file, $barrier, (string) $i, $mode]),
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($p)) { throw new RuntimeException('spawn failed'); }
                fclose($pipes[0]);
                $children[] = [$p, $pipes];
            }
            $deadline = hrtime(true) + 60000000000;
            do {
                clearstatcache();
                $ready = count(glob($barrier . '.*.ready') ?: []);
                if (hrtime(true) > $deadline) { throw new RuntimeException('workers not ready'); }
                if ($ready !== 32) { usleep(10000); }
            } while ($ready !== 32);
            file_put_contents($barrier, 'go');
            $totals = [];
            foreach ($children as [$p, $pipes]) {
                $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($p) !== 0 || $err !== '') { throw new RuntimeException('worker failure: ' . $err); }
                foreach (json_decode($out, true, 512, JSON_THROW_ON_ERROR) as $reason => $count) { $totals[$reason] = ($totals[$reason] ?? 0) + $count; }
            }
            $snapshot = $store->stats(StatsQuery::fromArray([]))->toArray();
            $expected = $mode === 'distinct' ? ['admit' => 10, 'global_limit' => 490] : ['admit' => 1, 'duplicate' => 499];
            if ($mode === 'init') { $expected = ['initialized' => 32]; }
            ksort($totals); ksort($expected);
            if ($totals !== $expected || ($mode !== 'init' && ($snapshot['totals']['total_seen'] !== 500 || $snapshot['totals']['total_admitted'] !== $expected['admit']))) {
                throw new RuntimeException('incoherent results: ' . json_encode($totals));
            }
            $row = ['backend' => $backend, 'iteration' => $iteration, 'mode' => $mode, 'workers' => 32, 'results' => $totals,
                'wall_ms' => round((hrtime(true) - $start) / 1000000, 1)];
            $report['runs'][] = $row;
            echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
            unset($children);
        }
    }
    $report['passed'] = true;
    if (!is_dir(dirname(__DIR__, 2) . '/.cache')) { mkdir(dirname(__DIR__, 2) . '/.cache', 0700, true); }
    file_put_contents(dirname(__DIR__, 2) . '/.cache/concurrency-' . PHP_VERSION . '-' . PHP_OS_FAMILY . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    if (isset($children)) { foreach ($children as [$p, $pipes]) { if (is_resource($p)) { proc_terminate($p); } } }
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
