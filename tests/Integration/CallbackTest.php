<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Integration;

use Kgkg\SentryDedupe\{Factory, FingerprintStrategyInterface};
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock};
use Kgkg\SentryDedupe\StatsQuery;
use Sentry\{Event, EventHint};
use PHPUnit\Framework\TestCase;

final class CallbackTest extends TestCase
{
    public function testPreviousCallbackAndReplacementBeforeReservation(): void
    {
        $c = Stores::config('json');
        $clock = new FakeClock();
        $store = Factory::store($c, $clock); $store->initialize();
        $d = Factory::create($c, $clock);
        $e = Event::createEvent()->setMessage('original');
        self::assertNull(($d->wrapBeforeSend(static function () { return null; }))($e));
        self::assertNull(($d->wrapBeforeSend(static function () { return false; }))($e));
        self::assertNull(($d->wrapBeforeSend(static function () { throw new \RuntimeException('private'); }))($e));
        self::assertSame(0, $store->stats(StatsQuery::fromArray([]))->toArray()['totals']['total_seen']);
        $replacement = Event::createEvent()->setMessage('replacement');
        self::assertSame($replacement, ($d->wrapBeforeSend(static function () use ($replacement) { return $replacement; }))($e, null));
        self::assertNotEmpty($replacement->getContexts()['sentry_dedupe']);
        self::assertNull($d($replacement));
        self::assertSame(2, $store->stats(StatsQuery::fromArray([]))->toArray()['totals']['total_seen']);
    }
    public function testDiagnosticsReentrancyRateLimitAndContextConflict(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock();
        Factory::store($c, $clock)->initialize();
        $count = 0; $d = null;
        $event = Event::createEvent()->setMessage('a')->setContext('sentry_dedupe', ['owned' => true]);
        $d = Factory::create($c, $clock, null, static function () use (&$count, &$d, $event): void {
            ++$count;
            self::assertSame('reentrant', $d->evaluate($event)->reason());
            self::assertNull($d($event));
            throw new \RuntimeException('diagnostic failure');
        });
        self::assertSame($event, $d($event));
        self::assertSame(['owned' => true], $event->getContexts()['sentry_dedupe']);
        for ($i = 0; $i < 10; ++$i) { self::assertNull($d($event)); }
        self::assertSame(3, $count); // admit, context_conflict, duplicate
        $callback = $d->wrapBeforeSend(static function (Event $e) use ($d): Event { self::assertNull($d($e)); return $e; });
        self::assertNotNull($callback(Event::createEvent()->setMessage('b')));
    }
    public function testMissingStorageInvalidStrategyAndFingerprintFailClosed(): void
    {
        $c = Stores::config('json', ['fingerprint' => ['strategy_id' => 'test-custom']]);
        $e = Event::createEvent()->setMessage('test');
        self::assertNull(Factory::create($c)($e));
        $strategy = new class implements FingerprintStrategyInterface {
            public function fingerprint(Event $event, ?EventHint $hint = null): string { throw new \RuntimeException('secret'); }
        };
        self::assertSame('fingerprint_error', Factory::create($c, null, $strategy)->evaluate($e)->reason());
        self::assertSame('fingerprint_error', Factory::create($c)->evaluate(Event::createEvent())->reason());
    }
}
