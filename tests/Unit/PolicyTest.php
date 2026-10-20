<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Unit;

use Kgkg\SentryDedupe\Policy\State;
use Kgkg\SentryDedupe\Tests\Fixtures\Stores;
use Kgkg\SentryDedupe\Exception\StorageException;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testOverflowRejectedBeforeIntegerConversion(): void
    {
        $this->expectException(StorageException::class);
        State::add(PHP_INT_MAX, 1);
    }
    public function testCapacityPrecedesQuotaAndDuplicatePrecedesQuota(): void
    {
        $c = Stores::config('json', ['max_fingerprints' => 1, 'global_limit' => ['max_events' => 1]]);
        $s = State::initial($c, 1800000000);
        $a = hash('sha256', 'a'); $b = hash('sha256', 'b');
        self::assertSame('admit', State::observe($s, $c, 1800000000, $a)->reason());
        self::assertSame('capacity', State::observe($s, $c, 1800000000, $b)->reason());
        self::assertSame('duplicate', State::observe($s, $c, 1800000000, $a)->reason());
        State::validate($s, $c);
        self::assertSame(1, $s['global_buckets'][1800000000]['admitted']);
    }
    public function testUncertainDataIsNull(): void
    {
        $d = new \Kgkg\SentryDedupe\Decision('storage_error');
        self::assertFalse($d->isAllowed());
        self::assertNull($d->fingerprint());
        self::assertNull($d->toArray()['total_seen']);
    }
}
