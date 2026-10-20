<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Integration;

use Kgkg\SentryDedupe\{Factory, StatsQuery};
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock};
use Sentry\{ClientBuilder, Event, Severity};
use Sentry\Transport\{TransportInterface, Result, ResultStatus};
use PHPUnit\Framework\TestCase;

final class SdkTest extends TestCase
{
    public function testRealSdkAllCapturePathsAndFailedDeliveryKeepsReservation(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock();
        $store = Factory::store($c, $clock); $store->initialize();
        $dedupe = Factory::create($c, $clock);
        $transport = new class implements TransportInterface {
            public int $calls = 0;
            public function send(Event $event): Result { ++$this->calls; return new Result(ResultStatus::failed(), $event); }
            public function close(?int $timeout = null): Result { return new Result(ResultStatus::success()); }
        };
        $builder = ClientBuilder::create(['dsn' => 'https://public@example.invalid/1', 'default_integrations' => false,
            'sample_rate' => 1.0, 'before_send' => $dedupe->wrapBeforeSend()]);
        $builder->setTransport($transport);
        $client = $builder->getClient();
        $exception = new \RuntimeException('database unavailable');
        for ($i = 0; $i < 500; ++$i) { $client->captureException($exception); }
        for ($i = 0; $i < 10; ++$i) { $client->captureMessage('plain message', Severity::error()); }
        $event = Event::createEvent()->setMessage('manual event');
        for ($i = 0; $i < 10; ++$i) { $client->captureEvent($event); }
        self::assertSame(3, $transport->calls);
        $totals = $store->stats(StatsQuery::fromArray([]))->toArray()['totals'];
        self::assertSame(520, $totals['total_seen']);
        self::assertSame(3, $totals['total_admitted']);
    }
}
