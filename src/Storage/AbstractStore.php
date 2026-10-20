<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\{Config, ClockInterface, Decision, StatsQuery, StatsSnapshot, CleanupOptions, CleanupResult, HealthReport};
use Kgkg\SentryDedupe\Exception\{StorageException, PolicyException, ConfigurationException};
use Kgkg\SentryDedupe\Policy\State;

abstract class AbstractStore implements StoreInterface
{
    protected Config $config;
    protected ClockInterface $clock;
    public function __construct(Config $config, ClockInterface $clock) { $this->config = $config; $this->clock = $clock; }

    /**
     * @param callable $operation
     * @return mixed
     */
    abstract protected function access(callable $operation, bool $write, bool $init = false, bool $allowPolicy = false);
    /** @return array<string, mixed> */
    abstract protected function probe(): array;

    public function initialize(): void
    {
        $this->access(static function (array &$s): void {}, false, true);
    }
    public function observe(string $fingerprint): Decision
    {
        return $this->access(function (array &$s) use ($fingerprint): Decision {
            return State::observe($s, $this->config, $this->clock->now(), $fingerprint);
        }, true);
    }
    public function stats(StatsQuery $query): StatsSnapshot
    {
        $q = $query->toArray();
        $window = $this->config->get('global_limit')['window_seconds'];
        if ($q['from'] !== null && ($q['from'] % $window !== 0 || $q['to'] % $window !== 0)) { throw new ConfigurationException('Range must align with quota windows'); }
        return $this->access(function (array &$s) use ($q, $window): StatsSnapshot {
            $time = $this->clock->now();
            State::time($time);
            $now = max($time, $s['last_effective_at']);
            $bucket = intdiv($now, $window) * $window;
            $records = array_values($s['fingerprints']);
            $retained = array_fill_keys(['total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global', 'pending_suppressed'], 0);
            foreach ($records as $record) { foreach ($retained as $field => $value) { $retained[$field] = State::add($value, $record[$field]); } }
            if ($q['fingerprint'] !== null) { $records = array_values(array_filter($records, static function (array $r) use ($q): bool { return $r['fingerprint'] === $q['fingerprint']; })); }
            usort($records, static function (array $a, array $b) use ($q): int {
                $compare = $q['sort'] === 'fingerprint' ? 0 : $b[$q['sort']] <=> $a[$q['sort']];
                return $compare ?: strcmp($a['fingerprint'], $b['fingerprint']);
            });
            $data = ['format_version' => 1, 'namespace' => $s['namespace'], 'snapshot_at' => $now, 'policy_hash' => $s['policy_hash'],
                'totals' => $s['totals'], 'retained_totals' => $retained, 'current_bucket' => ($s['global_buckets'][$bucket] ?? (['bucket_start' => $bucket] + array_fill_keys(State::BUCKET, 0)))
                    + ['max_events' => $this->config->get('global_limit')['max_events'], 'window_seconds' => $window],
                'retained_fingerprint_count' => count($s['fingerprints']), 'fingerprints' => array_slice($records, 0, $q['limit']),
                'history_complete_from' => $s['history_complete_from'], 'warnings' => []];
            if ($q['from'] !== null) {
                $sum = array_fill_keys(State::BUCKET, 0);
                foreach ($s['global_buckets'] as $b) {
                    if ($b['bucket_start'] >= $q['from'] && $b['bucket_start'] < $q['to']) {
                        foreach (State::BUCKET as $field) { $sum[$field] = State::add($sum[$field], $b[$field]); }
                    }
                }
                $data['bucket_range'] = ['from' => $q['from'], 'to' => $q['to'], 'totals' => $sum,
                    'complete' => $q['from'] >= $s['history_complete_from'] && $q['to'] <= $bucket];
                if (!$data['bucket_range']['complete']) { $data['warnings'][] = 'Range includes incomplete, expired, current or future buckets'; }
            }
            return new StatsSnapshot($data);
        }, false);
    }
    public function cleanup(CleanupOptions $options): CleanupResult
    {
        return $this->access(function (array &$s) use ($options): CleanupResult {
            $time = $this->clock->now();
            State::time($time);
            $now = max($time, $s['last_effective_at']);
            $cutoff = $now - $this->config->get('retention_seconds');
            $window = $this->config->get('global_limit')['window_seconds'];
            $candidates = [];
            foreach ($s['fingerprints'] as $key => $r) {
                if ($r['last_seen_at'] < $cutoff && ($r['last_admitted_at'] === null || $now - $r['last_admitted_at'] >= $this->config->get('interval_seconds'))) {
                    $candidates[] = ['time' => $r['last_seen_at'], 'key' => (string) $key, 'type' => 'fingerprints'];
                }
            }
            foreach ($s['global_buckets'] as $key => $b) {
                if ($b['bucket_start'] < intdiv($now, $window) * $window && State::add($b['bucket_start'], $window) < $cutoff) {
                    $candidates[] = ['time' => $b['bucket_start'], 'key' => (string) $key, 'type' => 'global_buckets'];
                }
            }
            usort($candidates, static function (array $a, array $b): int { return ($a['time'] <=> $b['time']) ?: strcmp($a['type'] . $a['key'], $b['type'] . $b['key']); });
            $counts = ['fingerprints' => 0, 'global_buckets' => 0];
            foreach (array_slice($candidates, 0, $options->limit()) as $item) {
                unset($s[$item['type']][$item['key']]);
                ++$counts[$item['type']];
                if ($item['type'] === 'global_buckets') { $s['history_complete_from'] = max($s['history_complete_from'], State::add($item['time'], $window)); }
            }
            if (!$options->isDryRun()) { $s['last_effective_at'] = $now; $s['generation'] = State::add($s['generation'], 1); }
            $temps = $this->cleanupTemps($options->isDryRun(), max(0, $options->limit() - array_sum($counts)), $now);
            return new CleanupResult(['format_version' => 1, 'dry_run' => $options->isDryRun(), 'deleted' => $counts + ['temporary_files' => $temps['deleted']],
                'remaining_work' => max(0, count($candidates) - $options->limit()) + $temps['remaining']]);
        }, !$options->isDryRun());
    }
    public function applyPolicy(string $expectedHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) { throw new ConfigurationException('Invalid expected policy hash'); }
        $this->access(function (array &$s) use ($expectedHash): void {
            if (!hash_equals($s['policy_hash'], $expectedHash)) { throw new PolicyException('expected_policy_mismatch'); }
            $new = $this->config->policy();
            $old = $s['policy'];
            if (($old['global_limit']['window_seconds'] ?? null) !== $new['global_limit']['window_seconds']
                || Config::canonical($old['fingerprint'] ?? null) !== Config::canonical($new['fingerprint'])) {
                throw new PolicyException('transition_requires_new_namespace');
            }
            $s['policy'] = $new;
            $s['policy_hash'] = $this->config->policyHash();
            $s['generation'] = State::add($s['generation'], 1);
        }, true, false, true);
    }
    public function healthCheck(): HealthReport
    {
        $checks = ['php' => PHP_VERSION, 'int_size' => PHP_INT_SIZE, 'backend' => $this->config->get('backend'),
            'sdk' => \Composer\InstalledVersions::getPrettyVersion('sentry/sentry'), 'sqlite3' => extension_loaded('sqlite3')];
        try {
            $this->stats(StatsQuery::fromArray([]));
            $checks += $this->probe();
            return new HealthReport(true, $checks + ['state' => 'valid']);
        } catch (\Throwable $e) {
            return new HealthReport(false, $checks + ['state' => $e instanceof StorageException ? $e->getMessage() : 'probe_failure']);
        }
    }
    /** @return array{deleted: int, remaining: int} */
    protected function cleanupTemps(bool $dryRun, int $limit, int $now): array { return ['deleted' => 0, 'remaining' => 0]; }

    /**
     * Contain native warnings without involving Sentry's global error handler.
     * @return mixed
     */
    protected function io(callable $operation)
    {
        set_error_handler(static function (): bool { return true; });
        try { return $operation(); }
        catch (StorageException $e) { throw $e; }
        catch (\Throwable $e) { throw new StorageException('io_failure', $e); }
        finally { restore_error_handler(); }
    }
    protected function safePath(string $path): void
    {
        $part = $path;
        while (true) {
            if (is_link($part)) { throw new StorageException('unsafe_symlink'); }
            $parent = dirname($part);
            if ($part === $parent) { break; }
            $part = $parent;
        }
    }
    protected function directory(string $path): void
    {
        $this->safePath($path);
        $deadline = hrtime(true) + $this->config->get('lock_timeout_ms') * 1000000;
        do {
            clearstatcache(true, $path);
            if (is_dir($path) || mkdir($path, 0700, true)) { return; }
            clearstatcache(true, $path);
            if (is_dir($path)) { return; }
            if (hrtime(true) >= $deadline) { throw new StorageException('mkdir_failed'); }
            usleep(1000);
        } while (true);
    }
}
