<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Integration;

use Kgkg\SentryDedupe\{Factory, StatsQuery, CleanupOptions};
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock, Faults, Process};
use Sentry\Event;
use PHPUnit\Framework\TestCase;

final class AtomicityTest extends TestCase
{
    protected function tearDown(): void { Faults::$operation = null; Faults::$checkpoint = null; }
    public function testJsonEncodedSizeCapKeepsPreviousCommittedSnapshot(): void
    {
        $c = Stores::config('json', ['json' => ['max_state_bytes' => 1048576], 'global_limit' => ['max_events' => 10000]]);
        $clock = new FakeClock(); $s = Factory::store($c, $clock); $s->initialize();
        $path = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace')) . '/state.json';
        $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $last = '';
        for ($i = 0; $i < 10000; ++$i) {
            \Kgkg\SentryDedupe\Policy\State::observe($state, $c, $clock->time, hash('sha256', 'size:' . $i));
            if ($i < 2000) { continue; }
            $copy = $state;
            $copy['fingerprints'] = (object) $copy['fingerprints']; $copy['global_buckets'] = (object) $copy['global_buckets'];
            $encoded = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 1048576) { break; }
            $last = $encoded;
        }
        self::assertNotSame('', $last);
        file_put_contents($path, $last);
        try { $s->observe(hash('sha256', 'size:extra')); self::fail('Encoded snapshot must exceed cap'); }
        catch (\Kgkg\SentryDedupe\Exception\StorageException $expected) { self::assertSame('state_size', $expected->getMessage()); }
        self::assertSame($last, file_get_contents($path));
        file_put_contents($path, str_repeat(' ', 1048577));
        self::assertNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('oversized input')));
    }
    public function testWriteFlushAndRenameFailureNeverReserve(): void
    {
        foreach (['write', 'zero_write', 'flush', 'rename'] as $fault) {
            $c = Stores::config('json'); $clock = new FakeClock();
            $s = Factory::store($c, $clock); $s->initialize();
            $before = $s->stats(StatsQuery::fromArray([]))->toArray();
            Faults::$operation = $fault;
            self::assertNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('secret')));
            Faults::$operation = null;
            self::assertSame($before, $s->stats(StatsQuery::fromArray([]))->toArray());
        }
    }
    public function testShortWritesAreCompletedAndTemporaryFilesOnlyCleanedAfter24Hours(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock(time());
        $s = Factory::store($c, $clock); $s->initialize();
        Faults::$operation = 'short_write';
        self::assertTrue($s->observe(hash('sha256', 'same'))->isAllowed());
        Faults::$operation = null;
        $dir = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace'));
        $old = $dir . '/.state.' . str_repeat('a', 32) . '.tmp';
        $young = $dir . '/.state.' . str_repeat('b', 32) . '.tmp';
        $other = $dir . '/.state.not-ours.tmp';
        foreach ([$old, $young, $other] as $file) { file_put_contents($file, 'abandoned'); }
        touch($old, $clock->time - 86401);
        touch($other, $clock->time - 86401);
        $s->cleanup(CleanupOptions::fromArray(['dry_run' => true]));
        self::assertFileExists($old);
        $r = $s->cleanup(CleanupOptions::fromArray([]))->toArray();
        self::assertSame(1, $r['deleted']['temporary_files']);
        self::assertFileDoesNotExist($old); self::assertFileExists($young); self::assertFileExists($other); self::assertFileExists($dir . '/state.lock');
    }
    public function testKillAtJsonCommitBoundariesLeavesWholeOldOrNewState(): void
    {
        foreach (['before_write', 'after_write', 'after_flush', 'before_rename', 'after_rename'] as $phase) {
            $c = Stores::config('json'); $clock = new FakeClock();
            $s = Factory::store($c, $clock); $s->initialize();
            $root = dirname($c->get('sqlite')['path']);
            $file = $root . '/crash-config.json';
            file_put_contents($file, json_encode($c->toSafeArray(), JSON_THROW_ON_ERROR));
            $p = proc_open(Process::command([dirname(__DIR__) . '/Fixtures/crash-worker.php', $file, $phase]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($p);
            stream_set_timeout($pipes[1], 10);
            self::assertSame("checkpoint\n", fgets($pipes[1]), $phase);
            proc_terminate($p);
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($p);
            $snapshot = $s->stats(StatsQuery::fromArray([]))->toArray();
            self::assertSame($phase === 'after_rename' ? 1 : 0, $snapshot['totals']['total_seen'], $phase);
            self::assertSame($phase === 'after_rename' ? 1 : 0, $snapshot['current_bucket']['admitted'], $phase);
            self::assertSame($phase !== 'after_rename', $s->observe(hash('sha256', 'same'))->isAllowed(), $phase);
        }
    }
    public function testSqliteBusyCommitRollsBackKeyAndBudget(): void
    {
        if (getenv('SENTRY_DEDUPE_BACKEND') === 'json') { self::markTestSkipped('SQLite-specific failure proof runs in SQLite job'); }
        $c = Stores::config('sqlite', ['sqlite' => ['journal_mode' => 'DELETE'], 'lock_timeout_ms' => 30]); $clock = new FakeClock();
        $s = Factory::store($c, $clock); $s->initialize();
        $db = new \SQLite3($c->get('sqlite')['path'], SQLITE3_OPEN_READWRITE); $db->enableExceptions(true);
        $db->exec('BEGIN'); $db->querySingle('SELECT count(*) FROM namespaces');
        try { self::assertNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('commit busy'))); }
        finally { $db->exec('ROLLBACK'); $db->close(); }
        self::assertSame(0, $s->stats(StatsQuery::fromArray([]))->toArray()['totals']['total_seen']);
        self::assertNotNull(Factory::create($c, $clock)(Event::createEvent()->setMessage('commit busy')));
    }

    public function testNativeSqliteKillBeforeAndAfterCommit(): void
    {
        if (getenv('SENTRY_DEDUPE_BACKEND') === 'json') { self::markTestSkipped('Native SQLite proof runs in SQLite job'); }
        foreach (['before_commit', 'after_commit'] as $phase) {
            $c = Stores::config('sqlite'); $clock = new FakeClock();
            $s = Factory::store($c, $clock); $s->initialize();
            $s->observe(hash('sha256', 'same'));
            $p = proc_open(Process::command([dirname(__DIR__) . '/Fixtures/sqlite-crash-worker.php', $c->get('sqlite')['path'], $phase]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            self::assertIsResource($p);
            stream_set_timeout($pipes[1], 10);
            self::assertSame("checkpoint\n", fgets($pipes[1]));
            proc_terminate($p);
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($p);
            $snapshot = $s->stats(StatsQuery::fromArray([]))->toArray();
            $n = $phase === 'after_commit' ? 2 : 1;
            self::assertSame($n, $snapshot['totals']['total_seen']);
            self::assertSame($n, $snapshot['fingerprints'][0]['total_seen']);
            self::assertSame($n, $snapshot['current_bucket']['observed']);
            self::assertSame(1, $snapshot['current_bucket']['admitted']);
        }
    }
}
