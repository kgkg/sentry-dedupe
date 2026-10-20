<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe;

use Kgkg\SentryDedupe\Clock\SystemClock;
use Kgkg\SentryDedupe\Fingerprint\DefaultFingerprintStrategy;
use Kgkg\SentryDedupe\Storage\{AbstractStore, JsonFileStore, SqliteStore};
use Kgkg\SentryDedupe\Exception\ConfigurationException;

final class Factory
{
    public static function create(Config $config, ?ClockInterface $clock = null, ?FingerprintStrategyInterface $strategy = null, ?callable $diagnostic = null): Deduplicator
    {
        if ($strategy !== null && $config->get('fingerprint')['strategy_id'] === 'default-fp-v1') { throw new ConfigurationException('Custom strategy requires explicit strategy_id'); }
        return new Deduplicator($config, self::store($config, $clock), $strategy ?? new DefaultFingerprintStrategy($config), $diagnostic);
    }
    public static function store(Config $config, ?ClockInterface $clock = null): AbstractStore
    {
        $clock = $clock ?? new SystemClock();
        return $config->get('backend') === 'json' ? new JsonFileStore($config, $clock) : new SqliteStore($config, $clock);
    }
}
