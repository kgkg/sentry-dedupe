<?php
declare(strict_types=1);

// Standalone process: no SDK or application error handler.
[$script, $backend, $path, $mode] = $argv;
$file = null;
$db = null;
set_error_handler(static function (): bool { return true; });
try {
    if ($backend === 'json') {
        $file = fopen($path, 'r+b');
        if ($file === false) { exit(1); }
        $locked = flock($file, LOCK_EX | LOCK_NB);
    } else {
        $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
        $db->enableExceptions(true);
        $db->busyTimeout(0);
        try { $locked = $db->exec('BEGIN IMMEDIATE'); }
        catch (Throwable $e) {
            if (!in_array($db->lastErrorCode() & 255, [5, 6], true)) { exit(1); }
            $locked = false;
        }
    }
    if ($mode === 'try') { echo $locked ? 'acquired' : 'blocked'; exit(0); }
    if (!$locked) { exit(1); }
    echo "locked\n";
    flush();
    fgets(STDIN);
    if (is_resource($file)) { flock($file, LOCK_UN); fclose($file); }
    if ($db instanceof SQLite3) { $db->exec('ROLLBACK'); $db->close(); }
} catch (Throwable $e) { exit(1); }
