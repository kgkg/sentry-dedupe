<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe;

interface ClockInterface
{
    public function now(): int;
}
