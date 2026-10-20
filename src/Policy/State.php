<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Policy;

use Kgkg\SentryDedupe\Config;
use Kgkg\SentryDedupe\Decision;
use Kgkg\SentryDedupe\Exception\StorageException;
use Kgkg\SentryDedupe\Exception\PolicyException;

/** Shared deterministic policy; only stores supply locked time and commit its output. */
final class State
{
    public const TOTALS = ['total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global', 'suppressed_capacity'];
    public const BUCKET = ['observed', 'admitted', 'suppressed_duplicate', 'suppressed_global', 'suppressed_capacity'];
    /** @return array<string, mixed> */
    public static function initial(Config $c, int $now): array
    {
        self::time($now);
        $window = $c->get('global_limit')['window_seconds'];
        return ['schema_version' => 1, 'namespace' => $c->get('namespace'), 'policy_hash' => $c->policyHash(),
            'policy' => $c->policy(), 'created_at' => $now, 'last_effective_at' => $now,
            'history_complete_from' => $now % $window === 0 ? $now : self::add(intdiv($now, $window) * $window, $window),
            'generation' => 0, 'totals' => array_fill_keys(self::TOTALS, 0), 'fingerprints' => [], 'global_buckets' => []];
    }
    public static function time(int $now): void
    {
        if ($now < 0 || $now > PHP_INT_MAX - 31536000) { throw new StorageException('invalid_clock'); }
    }
    public static function add(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || $a > PHP_INT_MAX - $b) { throw new StorageException('counter_overflow'); }
        return $a + $b;
    }
    /** @param array<string, mixed> $s */
    public static function validate(array $s, Config $c, bool $allowPolicy = false): void
    {
        $required = ['schema_version', 'namespace', 'policy_hash', 'policy', 'created_at', 'last_effective_at', 'history_complete_from', 'generation', 'totals', 'fingerprints', 'global_buckets'];
        if (array_diff($required, array_keys($s)) || array_diff(array_keys($s), $required) || $s['schema_version'] !== 1) { throw new PolicyException('schema_mismatch'); }
        if ($s['namespace'] !== $c->get('namespace') || !is_array($s['policy']) || !is_string($s['policy_hash'])
            || hash('sha256', Config::canonical($s['policy'])) !== $s['policy_hash']) { throw new StorageException('invalid_metadata'); }
        try {
            $storedValues = array_replace($c->toSafeArray(), $s['policy']);
            $storedValues['retention_seconds'] = max($c->get('retention_seconds'), $s['policy']['interval_seconds'] ?? 0, $s['policy']['global_limit']['window_seconds'] ?? 0);
            $storedConfig = Config::fromArray($storedValues);
            if ($storedConfig->policyHash() !== $s['policy_hash'] || ($s['policy']['namespace'] ?? null) !== $s['namespace']) { throw new StorageException('invalid_policy'); }
        } catch (\Kgkg\SentryDedupe\Exception\ConfigurationException $e) { throw new StorageException('invalid_policy', $e); }
        if (!$allowPolicy && $s['policy_hash'] !== $c->policyHash()) { throw new PolicyException('policy_mismatch'); }
        foreach (['created_at', 'last_effective_at', 'history_complete_from', 'generation'] as $key) { self::nonnegative($s[$key]); }
        self::time($s['last_effective_at']);
        if ($s['last_effective_at'] < $s['created_at'] || $s['history_complete_from'] < $s['created_at']) { throw new StorageException('invalid_time'); }
        if (!is_array($s['totals']) || !is_array($s['fingerprints']) || !is_array($s['global_buckets'])) { throw new StorageException('invalid_maps'); }
        self::counters($s['totals'], self::TOTALS);
        if (array_diff(array_keys($s['totals']), self::TOTALS)) { throw new StorageException('invalid_totals'); }
        self::equation($s['totals'], 'total_seen', ['total_admitted', 'suppressed_duplicate', 'suppressed_global', 'suppressed_capacity']);
        $retained = array_fill_keys(self::TOTALS, 0);
        foreach ($s['fingerprints'] as $key => $r) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key) || !is_array($r) || ($r['fingerprint'] ?? null) !== $key) { throw new StorageException('invalid_record'); }
            if (array_diff(array_keys($r), ['fingerprint', 'first_seen_at', 'last_seen_at', 'last_admitted_at', 'total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global', 'pending_suppressed'])) { throw new StorageException('invalid_record_fields'); }
            self::counters($r, ['first_seen_at', 'last_seen_at', 'total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global', 'pending_suppressed']);
            if (!array_key_exists('last_admitted_at', $r)) { throw new StorageException('invalid_record'); }
            if ($r['last_admitted_at'] !== null) { self::nonnegative($r['last_admitted_at']); }
            if ($r['first_seen_at'] < $s['created_at'] || $r['first_seen_at'] > $r['last_seen_at'] || $r['last_seen_at'] > $s['last_effective_at']
                || $r['total_seen'] < 1 || (($r['last_admitted_at'] === null) !== ($r['total_admitted'] === 0))
                || ($r['last_admitted_at'] !== null && ($r['last_admitted_at'] < $r['first_seen_at'] || $r['last_admitted_at'] > $r['last_seen_at']))
                || $r['pending_suppressed'] > self::add($r['suppressed_duplicate'], $r['suppressed_global'])) { throw new StorageException('invalid_record_time_counts'); }
            self::equation($r, 'total_seen', ['total_admitted', 'suppressed_duplicate', 'suppressed_global']);
            foreach (['total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global'] as $field) { $retained[$field] = self::add($retained[$field], $r[$field]); }
        }
        $window = $s['policy']['global_limit']['window_seconds'] ?? 0;
        if (!is_int($window) || $window < 1 || $window > 31536000) { throw new StorageException('invalid_policy'); }
        foreach ($s['global_buckets'] as $key => $b) {
            if (!preg_match('/^[0-9]+$/D', (string) $key) || !is_array($b)) { throw new StorageException('invalid_bucket'); }
            if (array_diff(array_keys($b), array_merge(['bucket_start'], self::BUCKET))) { throw new StorageException('invalid_bucket_fields'); }
            self::counters($b, array_merge(['bucket_start'], self::BUCKET));
            if ((string) $b['bucket_start'] !== (string) $key || $b['bucket_start'] % $window !== 0 || $b['bucket_start'] > $s['last_effective_at']) { throw new StorageException('invalid_bucket_time'); }
            self::equation($b, 'observed', ['admitted', 'suppressed_duplicate', 'suppressed_global', 'suppressed_capacity']);
            foreach (State::BUCKET as $field) {
                $totalField = ['observed' => 'total_seen', 'admitted' => 'total_admitted'][$field] ?? $field;
                if ($b[$field] > $s['totals'][$totalField]) { throw new StorageException('invalid_bucket_totals'); }
            }
        }
        foreach ($retained as $key => $value) { if ($value > $s['totals'][$key]) { throw new StorageException('invalid_retained_totals'); } }
    }
    /** @param mixed $value */
    private static function nonnegative($value): void
    {
        if (!is_int($value) || $value < 0) { throw new StorageException('invalid_integer'); }
    }
    /** @param array<string, mixed> $r @param string[] $fields */
    private static function counters(array $r, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $r)) { throw new StorageException('missing_counter'); }
            self::nonnegative($r[$field]);
        }
    }
    /** @param array<string, mixed> $r @param string[] $parts */
    private static function equation(array $r, string $sum, array $parts): void
    {
        $value = 0;
        foreach ($parts as $part) { $value = self::add($value, $r[$part]); }
        if ($r[$sum] !== $value) { throw new StorageException('counter_invariant'); }
    }
    /** @param array<string, mixed> $s */
    public static function observe(array &$s, Config $c, int $now, string $key, ?int $storedCount = null): Decision
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) { throw new StorageException('invalid_fingerprint'); }
        self::time($now);
        $now = max($now, $s['last_effective_at']);
        $s['last_effective_at'] = $now;
        $global = $c->get('global_limit');
        $bucket = intdiv($now, $global['window_seconds']) * $global['window_seconds'];
        if (!isset($s['global_buckets'][$bucket])) { $s['global_buckets'][$bucket] = ['bucket_start' => $bucket] + array_fill_keys(self::BUCKET, 0); }
        $b = &$s['global_buckets'][$bucket];
        $data = ['effective_at' => $now];
        $reason = Decision::CAPACITY;
        if (isset($s['fingerprints'][$key]) || ($storedCount ?? count($s['fingerprints'])) < $c->get('max_fingerprints')) {
            if (!isset($s['fingerprints'][$key])) {
                $s['fingerprints'][$key] = ['fingerprint' => $key, 'first_seen_at' => $now, 'last_seen_at' => $now,
                    'last_admitted_at' => null, 'total_seen' => 0, 'total_admitted' => 0,
                    'suppressed_duplicate' => 0, 'suppressed_global' => 0, 'pending_suppressed' => 0];
            }
            $r = &$s['fingerprints'][$key];
            $r['total_seen'] = self::add($r['total_seen'], 1);
            $r['last_seen_at'] = $now;
            if ($r['last_admitted_at'] !== null && $now - $r['last_admitted_at'] < $c->get('interval_seconds')) { $reason = Decision::DUPLICATE; }
            elseif ($b['admitted'] >= $global['max_events']) { $reason = Decision::GLOBAL_LIMIT; }
            else { $reason = Decision::ADMIT; }
            if ($reason === Decision::ADMIT) {
                $data['suppressed_since_previous_admission'] = $r['pending_suppressed'];
                $r['pending_suppressed'] = 0;
                $r['last_admitted_at'] = $now;
                $r['total_admitted'] = self::add($r['total_admitted'], 1);
            } else {
                $field = $reason === Decision::DUPLICATE ? 'suppressed_duplicate' : 'suppressed_global';
                $r[$field] = self::add($r[$field], 1);
                $r['pending_suppressed'] = self::add($r['pending_suppressed'], 1);
            }
            $data += array_intersect_key($r, array_flip(['total_seen', 'total_admitted', 'first_seen_at', 'last_seen_at', 'last_admitted_at']));
        }
        $s['totals']['total_seen'] = self::add($s['totals']['total_seen'], 1);
        $b['observed'] = self::add($b['observed'], 1);
        $field = ['admit' => 'total_admitted', 'duplicate' => 'suppressed_duplicate', 'global_limit' => 'suppressed_global', 'capacity' => 'suppressed_capacity'][$reason];
        $s['totals'][$field] = self::add($s['totals'][$field], 1);
        $bucketField = $reason === Decision::ADMIT ? 'admitted' : $field;
        $b[$bucketField] = self::add($b[$bucketField], 1);
        $s['generation'] = self::add($s['generation'], 1);
        return new Decision($reason, $key, $data);
    }
}
