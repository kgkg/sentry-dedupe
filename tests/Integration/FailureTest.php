<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Integration;

use Kgkg\SentryDedupe\{Factory, StatsQuery};
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock};
use Kgkg\SentryDedupe\Exception\StorageException;
use Sentry\Event;
use PHPUnit\Framework\TestCase;

final class FailureTest extends TestCase
{
    public function testJsonCorruptionLostSnapshotAndLockNeverReset(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock();
        $store = Factory::store($c, $clock); $store->initialize();
        $dir = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace'));
        $valid = file_get_contents($dir . '/state.json');
        $d = Factory::create($c, $clock);
        $e = Event::createEvent()->setMessage('private event');
        foreach (['', '{partial', str_replace('"fingerprints":{}', '"fingerprints":[]', $valid),
            str_replace('"total_seen":0', '"total_seen":"0"', $valid)] as $bad) {
            file_put_contents($dir . '/state.json', $bad);
            self::assertSame('storage_error', $d->evaluate($e)->reason());
            self::assertSame($bad, file_get_contents($dir . '/state.json'));
        }
        file_put_contents($dir . '/state.json', $valid);
        unlink($dir . '/state.json');
        self::assertNull($d($e));
        try { $store->initialize(); self::fail('Must not reset lost store'); } catch (StorageException $expected) {}
        self::assertFileDoesNotExist($dir . '/state.json');
        file_put_contents($dir . '/state.json', $valid);
        unlink($dir . '/state.lock');
        self::assertNull($d($e));
        self::assertFileDoesNotExist($dir . '/state.lock');
    }
    public function testJsonOverflowDoesNotReplaceSnapshot(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock();
        $store = Factory::store($c, $clock); $store->initialize();
        $dir = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace'));
        $s = json_decode(file_get_contents($dir . '/state.json'), true);
        $s['totals']['total_seen'] = PHP_INT_MAX;
        $s['totals']['suppressed_capacity'] = PHP_INT_MAX;
        $s['fingerprints'] = (object) []; $s['global_buckets'] = (object) [];
        $before = json_encode($s, JSON_THROW_ON_ERROR);
        file_put_contents($dir . '/state.json', $before);
        self::assertNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('a')));
        self::assertSame($before, file_get_contents($dir . '/state.json'));
    }
    public function testRealLockTimeoutsAndNativeSqliteBusy(): void
    {
        $backends = getenv('SENTRY_DEDUPE_BACKEND') ? [getenv('SENTRY_DEDUPE_BACKEND')] : ['json', 'sqlite'];
        foreach ($backends as $backend) {
            $c = Stores::config($backend, ['lock_timeout_ms' => 30]); $clock = new FakeClock();
            $store = Factory::store($c, $clock); $store->initialize();
            if ($backend === 'json') {
                $path = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace')) . '/state.lock';
                $lock = fopen($path, 'r+b'); flock($lock, LOCK_EX);
            } else {
                $db = new \SQLite3($c->get('sqlite')['path'], SQLITE3_OPEN_READWRITE); $db->enableExceptions(true); $db->exec('BEGIN IMMEDIATE');
            }
            try {
                $start = hrtime(true);
                self::assertNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('blocked')));
                self::assertLessThan(1000, (hrtime(true) - $start) / 1000000);
            } finally {
                if ($backend === 'json') { flock($lock, LOCK_UN); fclose($lock); }
                else { $db->exec('ROLLBACK'); $db->close(); }
            }
            self::assertSame(0, $store->stats(StatsQuery::fromArray([]))->toArray()['totals']['total_seen']);
        }
    }
}
