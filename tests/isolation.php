<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Kgkg\SentryDedupe\{Factory, StatsQuery, CleanupOptions};
use Kgkg\SentryDedupe\Tests\Fixtures\Stores;
use Sentry\Event;

if (extension_loaded('pdo') || extension_loaded('pdo_sqlite')) { fwrite(STDERR, "PDO must be unavailable for this isolation proof\n"); exit(1); }
$backend = getenv('SENTRY_DEDUPE_BACKEND') ?: 'json';
if ($backend === 'json' && extension_loaded('sqlite3')) { fwrite(STDERR, "SQLite3 must also be unavailable in JSON isolation\n"); exit(1); }
$c = Stores::config($backend);
$s = Factory::store($c);
$s->initialize();
$diagnostics = [];
$d = Factory::create($c, null, null, static function (string $reason) use (&$diagnostics): void { $diagnostics[] = $reason; });
$e = Event::createEvent()->setMessage('isolated');
$first = $d($e); $second = $d($e); $health = $s->healthCheck();
if ($first !== $e || $second !== null || !$health->isHealthy()) {
    fwrite(STDERR, json_encode(['first_allowed' => $first === $e, 'second_denied' => $second === null, 'diagnostics' => $diagnostics, 'stats' => $s->stats(StatsQuery::fromArray([]))->toArray(), 'health' => $health->toArray()], JSON_THROW_ON_ERROR) . "\n"); exit(1);
}
$s->stats(StatsQuery::fromArray([])); $s->cleanup(CleanupOptions::fromArray([]));
if (!extension_loaded('sqlite3')) {
    $missing = Stores::config('sqlite');
    if (Factory::create($missing)($e) !== null) { exit(1); }
    $report = Factory::store($missing)->healthCheck()->toArray();
    if ($report['healthy'] || $report['checks']['state'] !== 'missing_ext_sqlite3') { exit(1); }
}
echo json_encode(['isolation' => true, 'backend' => $backend, 'php' => PHP_VERSION, 'extensions' => get_loaded_extensions()], JSON_THROW_ON_ERROR) . "\n";
