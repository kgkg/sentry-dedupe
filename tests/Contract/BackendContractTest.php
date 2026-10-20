<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Contract;

use Kgkg\SentryDedupe\{Config, Factory, Decision, StatsQuery, CleanupOptions};
use Kgkg\SentryDedupe\Exception\{StorageException, PolicyException, ConfigurationException};
use Kgkg\SentryDedupe\Storage\AbstractStore;
use Kgkg\SentryDedupe\Tests\Fixtures\{FakeClock, Stores};
use PHPUnit\Framework\TestCase;

final class BackendContractTest extends TestCase
{
    /** @return array<string, array{string}> */
    public function backends(): array
    {
        $selected = getenv('SENTRY_DEDUPE_BACKEND');
        return $selected ? [$selected => [$selected]] : ['json' => ['json'], 'sqlite' => ['sqlite']];
    }
    /** @return array{Config, FakeClock, AbstractStore} @param array<string, mixed> $options */
    private function store(string $backend, array $options = []): array
    {
        $c = Stores::config($backend, $options);
        $clock = new FakeClock();
        $store = Factory::store($c, $clock);
        $store->initialize();
        return [$c, $clock, $store];
    }
    /** @dataProvider backends */
    public function testFiveHundredDurableObservations(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend);
        $start = $clock->time;
        $key = hash('sha256', 'same');
        for ($i = 0; $i < 500; ++$i) {
            $clock->time = $start + intdiv($i * 300, 500);
            $d = $store->observe($key);
            self::assertSame($i === 0 ? Decision::ADMIT : Decision::DUPLICATE, $d->reason());
        }
        $store = Factory::store($c, $clock);
        $s = $store->stats(StatsQuery::fromArray([]))->toArray();
        self::assertSame(['total_seen' => 500, 'total_admitted' => 1, 'suppressed_duplicate' => 499, 'suppressed_global' => 0, 'suppressed_capacity' => 0], $s['totals']);
        self::assertSame(499, $s['fingerprints'][0]['pending_suppressed']);
        $clock->time = $start + 3599;
        self::assertSame('duplicate', $store->observe($key)->reason());
        $clock->time = $start + 3600;
        $d = $store->observe($key);
        self::assertTrue($d->isAllowed());
        self::assertSame(500, $d->toArray()['suppressed_since_previous_admission']);
        self::assertSame(0, $store->stats(StatsQuery::fromArray([]))->toArray()['fingerprints'][0]['pending_suppressed']);
    }
    /** @dataProvider backends */
    public function testQuotaDenialDoesNotMoveCooldownAndRollbackDoesNotReopenBucket(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend, ['global_limit' => ['max_events' => 1]]);
        $a = hash('sha256', 'a'); $b = hash('sha256', 'b');
        self::assertTrue($store->observe($a)->isAllowed());
        $d = $store->observe($b);
        self::assertSame('global_limit', $d->reason());
        self::assertNull($d->toArray()['last_admitted_at']);
        $clock->time -= 7200;
        self::assertSame('global_limit', $store->observe($b)->reason());
        $clock->time += 10800;
        self::assertTrue($store->observe($b)->isAllowed());
        self::assertSame('duplicate', $store->observe($b)->reason());
        $s = $store->stats(StatsQuery::fromArray([]))->toArray();
        self::assertSame(1, $s['current_bucket']['admitted']);
        self::assertSame(5, $s['totals']['total_seen']);
    }
    /** @dataProvider backends */
    public function testCapacityExistingKeysAndNamespaceIsolation(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend, ['max_fingerprints' => 1]);
        $a = hash('sha256', 'a'); $b = hash('sha256', 'b');
        self::assertTrue($store->observe($a)->isAllowed());
        self::assertSame('capacity', $store->observe($b)->reason());
        self::assertSame('duplicate', $store->observe($a)->reason());
        $s = $store->stats(StatsQuery::fromArray([]))->toArray();
        self::assertSame(1, $s['retained_fingerprint_count']);
        self::assertSame(1, $s['totals']['suppressed_capacity']);
        $other = Config::fromArray(array_replace($c->toSafeArray(), ['namespace' => 'other:project:prod']));
        $isolated = Factory::store($other, $clock);
        $isolated->initialize();
        self::assertTrue($isolated->observe($a)->isAllowed());
    }
    /** @dataProvider backends */
    public function testFixedWindowBurstStillPreservesSameKeyCooldown(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend, ['global_limit' => ['max_events' => 2]]);
        $clock->time += 3599;
        foreach (['a', 'b'] as $key) { self::assertTrue($store->observe(hash('sha256', $key))->isAllowed()); }
        ++$clock->time;
        self::assertSame('duplicate', $store->observe(hash('sha256', 'a'))->reason());
        foreach (['c', 'd'] as $key) { self::assertTrue($store->observe(hash('sha256', $key))->isAllowed()); }
        self::assertSame('global_limit', $store->observe(hash('sha256', 'e'))->reason());
    }
    /** @dataProvider backends */
    public function testCleanupDryRunLimitsLifetimeAndCompleteness(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend, ['retention_seconds' => 3600]);
        $a = hash('sha256', 'a'); $b = hash('sha256', 'b');
        $store->observe($a);
        $clock->time += 7201;
        $store->observe($b);
        $before = $store->stats(StatsQuery::fromArray([]))->toArray();
        $dry = $store->cleanup(CleanupOptions::fromArray(['dry_run' => true]))->toArray();
        self::assertSame(1, $dry['deleted']['fingerprints']);
        self::assertSame($before, $store->stats(StatsQuery::fromArray([]))->toArray());
        $r = $store->cleanup(CleanupOptions::fromArray(['limit' => 1]))->toArray();
        self::assertSame(1, array_sum($r['deleted']));
        self::assertSame(1, $r['remaining_work']);
        $store->cleanup(CleanupOptions::fromArray([]));
        $after = $store->stats(StatsQuery::fromArray(['from' => 1800000000, 'to' => 1800007200]))->toArray();
        self::assertSame($before['totals'], $after['totals']);
        self::assertSame(1, $after['retained_fingerprint_count']);
        self::assertNotEmpty($after['warnings']);
        self::assertSame('duplicate', $store->observe($b)->reason());
    }
    /** @dataProvider backends */
    public function testPolicyApplyPreservesTimesAndCurrentBudget(string $backend): void
    {
        [$c, $clock, $store] = $this->store($backend);
        $a = hash('sha256', 'a');
        $store->observe($a);
        $new = Config::fromArray(array_replace($c->toSafeArray(), ['interval_seconds' => 1, 'global_limit' => ['window_seconds' => 3600, 'max_events' => 1]]));
        $changed = Factory::store($new, $clock);
        try { $changed->observe($a); self::fail('Policy must mismatch'); } catch (PolicyException $expected) {}
        $changed->applyPolicy($c->policyHash());
        ++$clock->time;
        self::assertSame('global_limit', $changed->observe($a)->reason());
        self::assertSame(1800000000, $changed->stats(StatsQuery::fromArray([]))->toArray()['fingerprints'][0]['last_admitted_at']);
        try { $store->observe($a); self::fail('Old workers must deny'); } catch (PolicyException $expected) {}
        $bad = Config::fromArray(array_replace_recursive($new->toSafeArray(), ['fingerprint' => ['include_line' => false]]));
        $this->expectException(PolicyException::class);
        Factory::store($bad, $clock)->applyPolicy($new->policyHash());
    }
    /** @dataProvider backends */
    public function testInitializationIsExplicitAndIdempotent(string $backend): void
    {
        $c = Stores::config($backend);
        $clock = new FakeClock();
        $store = Factory::store($c, $clock);
        try { $store->observe(hash('sha256', 'a')); self::fail('Missing store must fail'); } catch (StorageException $expected) {}
        $store->initialize();
        $store->observe(hash('sha256', 'a'));
        $store->initialize();
        self::assertSame(1, $store->stats(StatsQuery::fromArray([]))->toArray()['totals']['total_admitted']);
        self::assertTrue($store->healthCheck()->isHealthy());
    }
    /** @dataProvider backends */
    public function testRangeValidation(string $backend): void
    {
        [, , $store] = $this->store($backend);
        $this->expectException(ConfigurationException::class);
        $store->stats(StatsQuery::fromArray(['from' => 1, 'to' => 3600]));
    }
}
