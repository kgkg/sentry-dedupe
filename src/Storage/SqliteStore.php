<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\Config;
use Kgkg\SentryDedupe\Decision;
use Kgkg\SentryDedupe\Exception\StorageException;
use Kgkg\SentryDedupe\Policy\State;

final class SqliteStore extends AbstractStore
{
    private ?string $observing = null;
    private int $observationTime = 0;
    private int $storedCount = 0;
    private ?int $busyDeadline = null;
    private bool $waitingForBegin = false;

    public function observe(string $fingerprint): Decision
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) { throw new StorageException('invalid_fingerprint'); }
        $this->observing = $fingerprint;
        try {
            return $this->access(function (array &$s) use ($fingerprint): Decision {
                return State::observe($s, $this->config, $this->observationTime, $fingerprint, $this->storedCount);
            }, true);
        } finally { $this->observing = null; }
    }
    protected function access(callable $operation, bool $write, bool $init = false, bool $allowPolicy = false)
    {
        return $this->io(function () use ($operation, $write, $init, $allowPolicy) {
            $initLock = null;
            $db = null;
            $active = false;
            try {
                $remaining = $this->config->get('lock_timeout_ms');
                if ($init) {
                    // Serialize initial file creation and journal negotiation on Windows too.
                    // Schema/namespace creation still takes place in BEGIN IMMEDIATE.
                    $path = $this->config->get('sqlite')['path'];
                    $this->directory(dirname($path));
                    $this->safePath($path . '.init.lock');
                    $initLock = fopen($path . '.init.lock', 'c+b');
                    if ($initLock === false) { throw new StorageException('init_lock_open'); }
                    if (!chmod($path . '.init.lock', 0600)) { throw new StorageException('chmod_failed'); }
                    $deadline = hrtime(true) + $remaining * 1000000;
                    while (!flock($initLock, LOCK_EX | LOCK_NB)) {
                        if (hrtime(true) >= $deadline) { throw new StorageException('lock_timeout'); }
                        usleep(1000);
                    }
                    $remaining = max(1, (int) ceil(($deadline - hrtime(true)) / 1000000));
                }
                $openDeadline = hrtime(true) + $remaining * 1000000;
                $this->busyDeadline = $openDeadline;
                $this->waitingForBegin = true;
                do {
                    try {
                        if ($db === null) { $db = $this->open($init, min(20, max(1, (int) ceil(($openDeadline - hrtime(true)) / 1000000)))); }
                        $this->execute($db, $write || $init ? 'BEGIN IMMEDIATE' : 'BEGIN');
                        $active = true;
                        $this->waitingForBegin = false;
                        break;
                    } catch (StorageException $e) {
                        if ($e->getMessage() !== 'sqlite_busy_locked' || hrtime(true) >= $openDeadline) { throw $e; }
                        // Some SQLite open/journal paths report BUSY without invoking the busy handler.
                        // Retry only before a successful BEGIN, within one total deadline.
                        usleep(1000);
                    }
                } while (true);
                if ($init) { $this->schema($db); }
                $s = $this->load($db);
                if ($s === null) {
                    if (!$init) { throw new StorageException('missing_namespace'); }
                    $s = State::initial($this->config, $this->clock->now());
                    $this->insertNamespace($db, $s);
                }
                State::validate($s, $this->config, $allowPolicy);
                $old = $s;
                $result = $operation($s);
                if ($write) { State::validate($s, $this->config); $this->save($db, $s, $old); }
                $this->execute($db, 'COMMIT');
                $active = false;
                return $result;
            } finally {
                if ($active && $db !== null) { try { $db->exec('ROLLBACK'); } catch (\Throwable $ignored) {} }
                if ($db !== null) { $db->close(); }
                if (is_resource($initLock)) { flock($initLock, LOCK_UN); fclose($initLock); }
                $this->busyDeadline = null;
                $this->waitingForBegin = false;
            }
        });
    }
    private function open(bool $init, ?int $timeout = null): \SQLite3
    {
        if (!extension_loaded('sqlite3') || !class_exists(\SQLite3::class)) { throw new StorageException('missing_ext_sqlite3'); }
        if (version_compare(\SQLite3::version()['versionString'], '3.8.0', '<')) { throw new StorageException('unsupported_sqlite_engine'); }
        $options = $this->config->get('sqlite');
        $path = $options['path'];
        $this->safePath($path);
        if ($init) { $this->directory(dirname($path)); }
        // READWRITE alone never creates missing storage.
        $db = new \SQLite3($path, SQLITE3_OPEN_READWRITE | ($init ? SQLITE3_OPEN_CREATE : 0));
        $db->enableExceptions(true); // Return value is the previous mode, not success.
        try {
            if (!$db->busyTimeout($timeout ?? $this->config->get('lock_timeout_ms'))) { throw new StorageException('busy_timeout_setup'); }
            $this->execute($db, 'PRAGMA foreign_keys = ON');
            $this->execute($db, 'PRAGMA synchronous = FULL');
            if ($init) {
                $mode = $db->querySingle('PRAGMA journal_mode = ' . $options['journal_mode']);
                if (strtoupper((string) $mode) !== $options['journal_mode']) { throw new StorageException('journal_mode_mismatch'); }
                if (!chmod($path, 0600)) { throw new StorageException('chmod_failed'); }
            }
            if (strtoupper((string) $db->querySingle('PRAGMA journal_mode')) !== $options['journal_mode']
                || $db->querySingle('PRAGMA synchronous') !== 2 || $db->querySingle('PRAGMA foreign_keys') !== 1) { throw new StorageException('sqlite_settings_mismatch'); }
            return $db;
        } catch (\Throwable $e) {
            $failure = $e instanceof StorageException ? $e : $this->error($db, $e);
            try { $db->close(); } catch (\Throwable $ignored) {}
            throw $failure;
        }
    }
    private function schema(\SQLite3 $db): void
    {
        $counters = static function (array $fields): string {
            return implode(',', array_map(static function (string $field): string { return $field . ' INTEGER NOT NULL CHECK(typeof(' . $field . ")='integer' AND " . $field . '>=0)'; }, $fields));
        };
        $this->execute($db, 'CREATE TABLE IF NOT EXISTS namespaces (
            namespace TEXT PRIMARY KEY, schema_version INTEGER NOT NULL, policy_hash TEXT NOT NULL CHECK(length(policy_hash)=64),
            policy_json TEXT NOT NULL,' . $counters(array_merge(['created_at', 'last_effective_at', 'history_complete_from', 'generation'], State::TOTALS)) . ',
            CHECK(last_effective_at>=created_at),
            CHECK(total_seen=total_admitted+suppressed_duplicate+suppressed_global+suppressed_capacity))');
        $this->execute($db, 'CREATE TABLE IF NOT EXISTS fingerprints (
            namespace TEXT NOT NULL, fingerprint TEXT NOT NULL CHECK(length(fingerprint)=64 AND fingerprint NOT GLOB \'*[^0-9a-f]*\'),
            last_admitted_at INTEGER NULL CHECK(last_admitted_at IS NULL OR (typeof(last_admitted_at)=\'integer\' AND last_admitted_at>=0)), '
            . $counters(['first_seen_at', 'last_seen_at', 'total_seen', 'total_admitted', 'suppressed_duplicate', 'suppressed_global', 'pending_suppressed']) . ',
            PRIMARY KEY(namespace,fingerprint), FOREIGN KEY(namespace) REFERENCES namespaces(namespace),
            CHECK(last_seen_at>=first_seen_at), CHECK(total_seen=total_admitted+suppressed_duplicate+suppressed_global))');
        $this->execute($db, 'CREATE INDEX IF NOT EXISTS fingerprints_last_seen ON fingerprints(namespace,last_seen_at)');
        $this->execute($db, 'CREATE TABLE IF NOT EXISTS global_buckets (namespace TEXT NOT NULL,'
            . $counters(array_merge(['bucket_start'], State::BUCKET)) . ',
            PRIMARY KEY(namespace,bucket_start), FOREIGN KEY(namespace) REFERENCES namespaces(namespace),
            CHECK(observed=admitted+suppressed_duplicate+suppressed_global+suppressed_capacity))');
    }
    /** @return array<string, mixed>|null */
    private function load(\SQLite3 $db): ?array
    {
        $rows = $this->rows($db, 'SELECT * FROM namespaces WHERE namespace=:namespace', ['namespace' => $this->config->get('namespace')]);
        if (!$rows) { return null; }
        $s = $rows[0];
        $s['policy'] = json_decode($s['policy_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($s['policy_json']);
        $s['totals'] = [];
        foreach (State::TOTALS as $field) { $s['totals'][$field] = $s[$field]; unset($s[$field]); }
        $s['fingerprints'] = [];
        $s['global_buckets'] = [];
        $filter = [];
        if ($this->observing !== null) {
            $this->observationTime = $this->clock->now();
            State::time($this->observationTime);
            // Validate metadata before using persisted arithmetic; never trust corrupt types.
            State::validate($s, $this->config);
            $effective = max($this->observationTime, $s['last_effective_at']);
            $window = $this->config->get('global_limit')['window_seconds'];
            $filter = ['fingerprints' => $this->observing, 'global_buckets' => intdiv($effective, $window) * $window];
        }
        foreach (['fingerprints' => 'fingerprint', 'global_buckets' => 'bucket_start'] as $table => $key) {
            $params = ['namespace' => $s['namespace']];
            $where = '';
            if ($this->observing !== null) { $where = ' AND ' . $key . '=:key'; $params['key'] = $filter[$table]; }
            foreach ($this->rows($db, 'SELECT * FROM ' . $table . ' WHERE namespace=:namespace' . $where, $params) as $row) {
                unset($row['namespace']);
                $s[$table][$row[$key]] = $row;
            }
        }
        if ($this->observing !== null && !isset($s['fingerprints'][$this->observing])) {
            $count = $this->rows($db, 'SELECT count(*) AS n FROM fingerprints WHERE namespace=:namespace', ['namespace' => $s['namespace']]);
            $this->storedCount = $count[0]['n'];
        }
        return $s;
    }
    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function metadata(array $s): array
    {
        $row = array_diff_key($s, array_flip(['fingerprints', 'global_buckets', 'totals', 'policy']));
        return $row + $s['totals'] + ['policy_json' => Config::canonical($s['policy'])];
    }
    /** @param array<string, mixed> $s */
    private function insertNamespace(\SQLite3 $db, array $s): void { $this->insert($db, 'namespaces', $this->metadata($s)); }
    /** @param array<string, mixed> $s @param array<string, mixed> $old */
    private function save(\SQLite3 $db, array $s, array $old): void
    {
        $row = $this->metadata($s);
        unset($row['namespace']);
        $this->execute($db, 'UPDATE namespaces SET ' . implode(',', array_map(static function (string $f): string { return $f . '=:' . $f; }, array_keys($row))) . ' WHERE namespace=:namespace', $row + ['namespace' => $s['namespace']]);
        foreach (['fingerprints' => 'fingerprint', 'global_buckets' => 'bucket_start'] as $table => $key) {
            foreach ($old[$table] as $id => $previous) {
                if (!isset($s[$table][$id])) { $this->execute($db, 'DELETE FROM ' . $table . ' WHERE namespace=:namespace AND ' . $key . '=:id', ['namespace' => $s['namespace'], 'id' => $previous[$key]]); }
            }
            foreach ($s[$table] as $id => $record) {
                if (isset($old[$table][$id]) && $old[$table][$id] === $record) { continue; }
                if (isset($old[$table][$id])) {
                    $values = $record;
                    unset($values[$key]);
                    $this->execute($db, 'UPDATE ' . $table . ' SET ' . implode(',', array_map(static function (string $f): string { return $f . '=:' . $f; }, array_keys($values)))
                        . ' WHERE namespace=:namespace AND ' . $key . '=:id', $values + ['namespace' => $s['namespace'], 'id' => $record[$key]]);
                } else { $this->insert($db, $table, ['namespace' => $s['namespace']] + $record); }
            }
        }
    }
    /** @param array<string, mixed> $row */
    private function insert(\SQLite3 $db, string $table, array $row): void
    {
        $this->execute($db, 'INSERT INTO ' . $table . '(' . implode(',', array_keys($row)) . ') VALUES(:' . implode(',:', array_keys($row)) . ')', $row);
    }
    /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
    private function rows(\SQLite3 $db, string $sql, array $params = [], bool $read = true): array
    {
        $stmt = null;
        $result = null;
        $failure = null;
        $rows = [];
        try {
            if ($this->busyDeadline !== null) {
                $budget = max(0, (int) ceil(($this->busyDeadline - hrtime(true)) / 1000000));
                if (!$db->busyTimeout($this->waitingForBegin ? min(20, $budget) : $budget)) { throw new StorageException('busy_timeout_setup'); }
            }
            $stmt = $db->prepare($sql);
            if ($stmt === false) { throw $this->error($db); }
            foreach ($params as $key => $value) {
                if (!is_int($value) && !is_string($value) && $value !== null) { throw new StorageException('invalid_binding'); }
                if (!$stmt->bindValue(':' . $key, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT))) { throw $this->error($db); }
            }
            $result = $stmt->execute();
            if ($result === false) { throw $this->error($db); }
            if ($read) { while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) { $rows[] = $row; } }
            if ($db->lastErrorCode() !== 0 && $db->lastErrorCode() !== 101) { throw $this->error($db); }
        } catch (\Throwable $e) { $failure = $e instanceof StorageException ? $e : $this->error($db, $e); }
        finally {
            try {
                if ($result instanceof \SQLite3Result && !$result->finalize()) { throw $this->error($db); }
            } catch (\Throwable $e) { if ($failure === null) { $failure = $this->error($db, $e); } }
            try {
                if ($stmt instanceof \SQLite3Stmt && !$stmt->close()) { throw $this->error($db); }
            } catch (\Throwable $e) { if ($failure === null) { $failure = $this->error($db, $e); } }
        }
        if ($failure !== null) { throw $failure; }
        return $rows;
    }
    /** @param array<string, mixed> $params */
    private function execute(\SQLite3 $db, string $sql, array $params = []): void { $this->rows($db, $sql, $params, false); }
    private function error(\SQLite3 $db, ?\Throwable $previous = null): StorageException
    {
        $code = $db->lastErrorCode() & 255;
        return new StorageException(in_array($code, [5, 6], true) ? 'sqlite_busy_locked' : 'sqlite_error_' . $code, $previous);
    }
    protected function probe(): array
    {
        return $this->io(function (): array {
            $db = $this->open(false);
            try {
                if ($db->querySingle('PRAGMA quick_check') !== 'ok' || $this->rows($db, 'PRAGMA foreign_key_check')) { throw new StorageException('sqlite_integrity'); }
            } finally { $db->close(); }
            return ['sqlite_engine' => \SQLite3::version()['versionString'], 'integrity' => 'ok',
                'locking' => LockProbe::sqlite($this->config->get('sqlite')['path'])];
        });
    }
}
