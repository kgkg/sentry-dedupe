<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\Decision;
use Kgkg\SentryDedupe\StatsQuery;
use Kgkg\SentryDedupe\StatsSnapshot;
use Kgkg\SentryDedupe\CleanupOptions;
use Kgkg\SentryDedupe\CleanupResult;
use Kgkg\SentryDedupe\HealthReport;

interface StoreInterface
{
    public function observe(string $fingerprint): Decision;
    public function stats(StatsQuery $query): StatsSnapshot;
    public function cleanup(CleanupOptions $options): CleanupResult;
    public function healthCheck(): HealthReport;
}
