<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kgkg\SentryDedupe\{Config, Factory, StatsQuery, CleanupOptions};
use Kgkg\SentryDedupe\Tests\Fixtures\FakeClock;

[$script, $configFile, $barrier, $index, $mode] = $argv;
try {
    $c = Config::fromArray(json_decode(file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR));
    $clock = new FakeClock();
    $store = Factory::store($c, $clock);
    file_put_contents($barrier . '.' . $index . '.ready', 'ready');
    $deadline = hrtime(true) + 60000000000;
    while (!is_file($barrier)) {
        clearstatcache(true, $barrier);
        if (hrtime(true) > $deadline) { throw new RuntimeException('barrier timeout'); }
        usleep(1000);
    }
    $counts = [];
    if ($mode === 'init') { $store->initialize(); $counts['initialized'] = 1; }
    else {
        $n = (int) $index < 20 ? 16 : 15;
        for ($i = 0; $i < $n; ++$i) {
            if ($mode === 'mixed' && (int) $index === 0) { $store->cleanup(CleanupOptions::fromArray([])); }
            if ($mode === 'mixed' && (int) $index === 1) { $store->stats(StatsQuery::fromArray([])); }
            $key = hash('sha256', $mode === 'distinct' ? $index . ':' . $i : 'same');
            $reason = $store->observe($key)->reason();
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
    }
    echo json_encode($counts, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    do { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $e = $e->getPrevious(); } while ($e !== null);
    exit(1);
}
