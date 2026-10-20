<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe;

use Kgkg\SentryDedupe\Exception\ConfigurationException;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values) { $this->values = $values; }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        if (PHP_INT_SIZE !== 8) { throw new ConfigurationException('64-bit PHP required'); }
        $defaults = [
            'namespace' => null, 'backend' => null,
            'sqlite' => ['path' => null, 'journal_mode' => 'WAL', 'synchronous' => 'FULL'],
            'json' => ['directory' => null, 'max_state_bytes' => 16777216],
            'interval_seconds' => 3600, 'global_limit' => ['max_events' => 100, 'window_seconds' => 3600],
            'lock_timeout_ms' => 100, 'retention_seconds' => 604800, 'max_fingerprints' => 10000,
            'fingerprint' => ['version' => 'fp-v1', 'project_root' => null, 'deployment_prefixes' => [],
                'include_release' => false, 'include_line' => true, 'max_frames' => 5,
                'normalization_rules' => [], 'strategy_id' => 'default-fp-v1'],
            'event_context' => true,
        ];
        self::keys($values, $defaults);
        foreach (['sqlite', 'json', 'global_limit', 'fingerprint'] as $section) {
            if (isset($values[$section])) {
                if (!is_array($values[$section])) { throw new ConfigurationException('Invalid section: ' . $section); }
                self::keys($values[$section], $defaults[$section]);
                $values[$section] = array_replace($defaults[$section], $values[$section]);
            } elseif (array_key_exists($section, $values)) { throw new ConfigurationException('Invalid section'); }
        }
        $v = array_replace($defaults, $values);
        if (!is_string($v['namespace']) || strlen($v['namespace']) < 1 || strlen($v['namespace']) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $v['namespace']) || !preg_match('//u', $v['namespace'])) {
            throw new ConfigurationException('Invalid namespace');
        }
        if (!in_array($v['backend'], ['json', 'sqlite'], true)) { throw new ConfigurationException('Invalid backend'); }
        foreach (['sqlite' => 'path', 'json' => 'directory'] as $section => $key) {
            if ($v[$section][$key] !== null) { $v[$section][$key] = self::path($v[$section][$key]); }
            if ($v['backend'] === $section && $v[$section][$key] === null) { throw new ConfigurationException('Missing storage path'); }
        }
        self::integer($v['interval_seconds'], 1, 31536000);
        self::integer($v['global_limit']['max_events'], 1, PHP_INT_MAX);
        self::integer($v['global_limit']['window_seconds'], 1, 31536000);
        self::integer($v['lock_timeout_ms'], 1, 5000);
        self::integer($v['max_fingerprints'], 1, 100000);
        self::integer($v['retention_seconds'], max($v['interval_seconds'], $v['global_limit']['window_seconds']), PHP_INT_MAX);
        self::integer($v['json']['max_state_bytes'], 1048576, 268435456);
        if (!in_array($v['sqlite']['journal_mode'], ['WAL', 'DELETE'], true) || $v['sqlite']['synchronous'] !== 'FULL') {
            throw new ConfigurationException('SQLite requires WAL/DELETE and FULL');
        }
        $fp = $v['fingerprint'];
        if ($fp['version'] !== 'fp-v1' || !is_string($fp['strategy_id']) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $fp['strategy_id'])) {
            throw new ConfigurationException('Invalid fingerprint strategy/version');
        }
        foreach ([$v['event_context'], $fp['include_release'], $fp['include_line']] as $flag) {
            if (!is_bool($flag)) { throw new ConfigurationException('Expected boolean'); }
        }
        self::integer($fp['max_frames'], 1, 20);
        if ($fp['project_root'] !== null) { $fp['project_root'] = self::path($fp['project_root']); }
        if (!is_array($fp['deployment_prefixes']) || !is_array($fp['normalization_rules'])) { throw new ConfigurationException('Invalid normalization configuration'); }
        if ($fp['normalization_rules'] !== [] && array_keys($fp['normalization_rules']) !== range(0, count($fp['normalization_rules']) - 1)) { throw new ConfigurationException('Normalization rules must be a list'); }
        $prefixes = [];
        foreach ($fp['deployment_prefixes'] as $path => $token) {
            if (!is_string($path) || !is_string($token) || !preg_match('/^<[a-zA-Z0-9_-]+>$/D', $token)) { throw new ConfigurationException('Invalid deployment prefix'); }
            $prefixes[self::path($path)] = $token;
        }
        $fp['deployment_prefixes'] = $prefixes;
        foreach ($fp['normalization_rules'] as $rule) {
            if (!is_array($rule) || count($rule) !== 2 || !isset($rule['pattern'], $rule['replacement']) || !is_string($rule['pattern']) || !is_string($rule['replacement'])) {
                throw new ConfigurationException('Invalid normalization rule');
            }
            set_error_handler(static function (): bool { return true; });
            try { $valid = preg_replace($rule['pattern'], $rule['replacement'], 'configuration validation'); }
            finally { restore_error_handler(); }
            if ($valid === null) { throw new ConfigurationException('Invalid normalization regex'); }
        }
        $v['fingerprint'] = $fp;
        // Detach PHP array references as well as rejecting non-JSON/invalid UTF-8 values.
        // An immutable Config must not change when the caller later assigns a referenced variable.
        try { $v = json_decode(self::canonical($v), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new ConfigurationException('Configuration must be JSON/UTF-8 compatible', 0, $e); }
        return new self($v);
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array { return $this->values; }

    /** @return mixed */
    public function get(string $key) { return $this->values[$key]; }

    public function policyHash(): string { return hash('sha256', self::canonical($this->policy())); }

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return array_intersect_key($this->values, array_flip(['namespace', 'interval_seconds', 'global_limit', 'fingerprint']));
    }

    /** @param mixed $value */
    public static function canonical($value): string
    {
        $sort = static function ($item) use (&$sort) {
            if (is_array($item)) {
                if ($item !== [] && array_keys($item) !== range(0, count($item) - 1)) { ksort($item, SORT_STRING); }
                foreach ($item as &$child) { $child = $sort($child); }
                unset($child);
            }
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $values @param array<mixed> $allowed */
    private static function keys(array $values, array $allowed): void
    {
        if (array_diff_key($values, $allowed)) { throw new ConfigurationException('Unknown configuration keys'); }
    }

    /** @param mixed $value */
    public static function integer($value, int $min, int $max): void
    {
        if (!is_int($value) || $value < $min || $value > $max) { throw new ConfigurationException('Integer out of range'); }
    }

    /** @param mixed $path */
    public static function path($path): string
    {
        if (!is_string($path) || preg_match('/[\x00-\x1f\x7f]/', $path)) { throw new ConfigurationException('Invalid local absolute path'); }
        $path = str_replace('\\', '/', $path);
        if (!preg_match('~^(?:[A-Za-z]:/|/(?!/))~', $path) || strpos($path, '://') !== false
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || strpos(substr($path, 2), ':') !== false) {
            throw new ConfigurationException('Expected absolute local path without dot segments');
        }
        return rtrim($path, '/') . (preg_match('~^(?:[A-Za-z]:/|/)$~', $path) ? '/' : '');
    }
}
