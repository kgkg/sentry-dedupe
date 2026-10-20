<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe\Clock;

final class SystemClock implements \Kgkg\SentryDedupe\ClockInterface
{
    public function now(): int { return time(); }
}
