<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Check the actual native engine's atomic commit behavior against the library schema.
[$script, $path, $phase] = $argv;
$db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
$db->enableExceptions(true);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA synchronous=FULL');
$db->exec('BEGIN IMMEDIATE');
$db->exec('UPDATE namespaces SET total_seen=total_seen+1,suppressed_duplicate=suppressed_duplicate+1,generation=generation+1');
$db->exec('UPDATE fingerprints SET total_seen=total_seen+1,suppressed_duplicate=suppressed_duplicate+1,pending_suppressed=pending_suppressed+1');
$db->exec('UPDATE global_buckets SET observed=observed+1,suppressed_duplicate=suppressed_duplicate+1');
if ($phase === 'after_commit') { $db->exec('COMMIT'); }
echo "checkpoint\n"; flush();
fgets(STDIN);
if ($phase === 'before_commit') { $db->exec('COMMIT'); }
$db->close();
