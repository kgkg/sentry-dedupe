<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Fixtures;

final class FakeClock implements \Kgkg\SentryDedupe\ClockInterface
{
    public int $time;
    public function __construct(int $time = 1800000000) { $this->time = $time; }
    public function now(): int { return $this->time; }
}
