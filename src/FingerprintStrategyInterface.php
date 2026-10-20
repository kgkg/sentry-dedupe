<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe;

use Sentry\Event;
use Sentry\EventHint;

interface FingerprintStrategyInterface
{
    public function fingerprint(Event $event, ?EventHint $hint = null): string;
}
