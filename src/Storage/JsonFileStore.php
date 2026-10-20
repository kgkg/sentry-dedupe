<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\{Config, ClockInterface};
use Kgkg\SentryDedupe\Exception\StorageException;
use Kgkg\SentryDedupe\Policy\State;

final class JsonFileStore extends AbstractStore
{
    private string $directory;
    public function __construct(Config $config, ClockInterface $clock)
    {
        parent::__construct($config, $clock);
        $this->directory = $config->get('json')['directory'] . '/' . hash('sha256', $config->get('namespace'));
    }
    protected function access(callable $operation, bool $write, bool $init = false, bool $allowPolicy = false)
    {
        return $this->io(function () use ($operation, $write, $init, $allowPolicy) {
            $dir = $this->directory;
            $this->safePath($dir . '/state.lock');
            $this->safePath($dir . '/state.json');
            if ($init) { $this->directory($dir); }
            if ($init && !file_exists($dir . '/state.lock') && file_exists($dir . '/state.json')) { throw new StorageException('missing_lock'); }
            $lock = fopen($dir . '/state.lock', $init ? 'c+b' : 'r+b');
            if ($lock === false) { throw new StorageException('missing_lock'); }
            $locked = false;
            try {
                if ($init && !chmod($dir . '/state.lock', 0600)) { throw new StorageException('chmod_failed'); }
                $deadline = hrtime(true) + $this->config->get('lock_timeout_ms') * 1000000;
                do {
                    $locked = flock($lock, ($write || $init ? LOCK_EX : LOCK_SH) | LOCK_NB);
                    if ($locked) { break; }
                    if (hrtime(true) >= $deadline) { throw new StorageException('lock_timeout'); }
                    usleep(1000);
                } while (true);
                clearstatcache(true, $dir . '/state.json');
                if ($init && !file_exists($dir . '/state.json')) {
                    // An existing lock indicates an interrupted/lost store: never silently reset it.
                    if (filesize($dir . '/state.lock') !== 0) { throw new StorageException('interrupted_initialization'); }
                    $s = State::initial($this->config, $this->clock->now());
                    if (fwrite($lock, 'sentry-dedupe-v1') !== strlen('sentry-dedupe-v1') || !fflush($lock)) { throw new StorageException('lock_marker_failed'); }
                    $this->save($s);
                } else { $s = $this->read(); }
                State::validate($s, $this->config, $allowPolicy);
                $result = $operation($s);
                if ($write) { State::validate($s, $this->config); $this->save($s); }
                return $result;
            } finally {
                if ($locked) { flock($lock, LOCK_UN); }
                fclose($lock);
            }
        });
    }
    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->directory . '/state.json';
        $cap = $this->config->get('json')['max_state_bytes'];
        $file = fopen($path, 'rb');
        if ($file === false) { throw new StorageException('missing_state'); }
        try {
            $stat = fstat($file);
            if ($stat === false || $stat['size'] < 2 || $stat['size'] > $cap) { throw new StorageException('state_size'); }
            $json = stream_get_contents($file, $cap + 1);
            if ($json === false || strlen($json) > $cap || strlen($json) !== $stat['size']) { throw new StorageException('state_read'); }
        } finally { fclose($file); }
        $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!($object instanceof \stdClass) || !($object->fingerprints ?? null) instanceof \stdClass || !($object->global_buckets ?? null) instanceof \stdClass) { throw new StorageException('invalid_maps'); }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
    /** @param array<string, mixed> $s */
    private function save(array $s): void
    {
        $s['fingerprints'] = (object) $s['fingerprints'];
        $s['global_buckets'] = (object) $s['global_buckets'];
        $json = json_encode($s, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->config->get('json')['max_state_bytes']) { throw new StorageException('state_size'); }
        $this->replace($this->directory . '/state.json', $json);
    }
    private function replace(string $target, string $data): void
    {
        $tmp = $this->directory . '/.state.' . bin2hex(random_bytes(16)) . '.tmp';
        $file = fopen($tmp, 'x+b');
        if ($file === false) { throw new StorageException('temp_create'); }
        try {
            if (!chmod($tmp, 0600)) { throw new StorageException('chmod_failed'); }
            $offset = 0;
            while ($offset < strlen($data)) {
                $n = fwrite($file, substr($data, $offset));
                if ($n === false || $n === 0) { throw new StorageException('short_write'); }
                $offset += $n;
            }
            if (!fflush($file)) { throw new StorageException('flush_failed'); }
        } catch (\Throwable $e) { fclose($file); throw $e; }
        if (!fclose($file)) { throw new StorageException('close_failed'); }
        // Windows scanners may briefly open a closed snapshot without delete sharing.
        // Every attempt remains an atomic rename; there is no unlink/copy fallback.
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            if (rename($tmp, $target)) { return; }
            if ($attempt < 19) { usleep(5000); }
        }
        throw new StorageException('atomic_replace_failed');
    }
    protected function cleanupTemps(bool $dryRun, int $limit, int $now): array
    {
        $files = glob($this->directory . '/.state.*.tmp');
        if ($files === false) { throw new StorageException('tmp_list_failed'); }
        $eligible = [];
        foreach ($files as $file) {
            if (!preg_match('/^\.state\.[a-f0-9]{32}\.tmp$/D', basename($file)) || is_link($file) || !is_file($file)) { continue; }
            $mtime = filemtime($file);
            if ($mtime === false) { throw new StorageException('tmp_stat_failed'); }
            if ($mtime < $now - 86400) { $eligible[] = ['path' => $file, 'time' => $mtime]; }
        }
        usort($eligible, static function (array $a, array $b): int { return ($a['time'] <=> $b['time']) ?: strcmp($a['path'], $b['path']); });
        foreach (array_slice($eligible, 0, $limit) as $file) {
            $this->safePath($file['path']);
            if (!$dryRun && !unlink($file['path'])) { throw new StorageException('tmp_unlink_failed'); }
        }
        return ['deleted' => min($limit, count($eligible)), 'remaining' => max(0, count($eligible) - $limit)];
    }
    protected function probe(): array
    {
        return $this->io(function (): array {
            $probe = $this->directory . '/.probe.' . bin2hex(random_bytes(16));
            $failure = null;
            try {
                $this->replace($probe, 'old');
                $this->replace($probe, 'new');
                if (file_get_contents($probe) !== 'new') { throw new StorageException('replacement_probe_failed'); }
            } catch (\Throwable $e) { $failure = $e; }
            if (is_file($probe) && !unlink($probe) && $failure === null) { throw new StorageException('probe_cleanup'); }
            if ($failure !== null) { throw $failure; }
            return ['atomic_replace' => true, 'locking' => LockProbe::json($this->directory . '/state.lock')];
        });
    }
}
