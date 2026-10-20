<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\Exception\StorageException;

/** Two independent workers verify exclusion on this filesystem and interpreter. */
final class LockProbe
{
    public static function json(string $path): bool { return self::run('json', $path); }
    public static function sqlite(string $path): bool { return self::run('sqlite', $path); }
    private static function run(string $backend, string $path): bool
    {
        if (!function_exists('proc_open')) { throw new StorageException('process_probe_unavailable'); }
        $worker = __DIR__ . '/lock-probe.php';
        $command = [PHP_BINARY, '-c', php_ini_loaded_file() ?: '', $worker, $backend, $path];
        // Preserve -n/extension isolation; explicitly forward the extensions needed by the child.
        if (!php_ini_loaded_file()) {
            $command = [PHP_BINARY, '-n'];
            $binary = rtrim((string) ini_get('extension_dir'), '/\\') . '/' . (PHP_OS_FAMILY === 'Windows' ? 'php_sqlite3.dll' : 'sqlite3.so');
            if (extension_loaded('sqlite3') && is_file($binary)) { $command = array_merge($command, ['-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=sqlite3']); }
            $command = array_merge($command, [$worker, $backend, $path]);
        }
        $holder = proc_open(array_merge($command, ['hold']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($holder)) { throw new StorageException('process_probe_start'); }
        try {
            stream_set_timeout($pipes[1], 5);
            if (trim((string) fgets($pipes[1])) !== 'locked') { throw new StorageException('process_probe_holder'); }
            $contender = proc_open(array_merge($command, ['try']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp, null, null, ['bypass_shell' => true]);
            if (!is_resource($contender)) { throw new StorageException('process_probe_contender'); }
            fclose($cp[0]);
            stream_set_timeout($cp[1], 5);
            $result = trim((string) stream_get_contents($cp[1]));
            fclose($cp[1]); fclose($cp[2]);
            $exit = proc_close($contender);
            if ($exit !== 0 || $result !== 'blocked') { throw new StorageException('process_locking_failed'); }
        } finally {
            fwrite($pipes[0], "release\n");
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($holder);
        }
        return true;
    }
}
