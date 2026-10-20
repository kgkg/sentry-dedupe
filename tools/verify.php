<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

/** Reproducible local checks, with evidence retained rather than only console output. */
$root = dirname(__DIR__);
if (PHP_OS_FAMILY === 'Windows') { putenv('PHP_INI_SCAN_DIR=' . $root . '/.local/no-ini'); }
$php = \Kgkg\SentryDedupe\Tests\Fixtures\Process::command([]);
$evidence = $root . '/docs/evidence';
if (!is_dir($evidence)) { mkdir($evidence, 0700, true); }
$commands = [
    'lint-74' => array_merge($php, [$root . '/tools/lint.php']),
    'unit-74' => array_merge($php, [$root . '/vendor/phpunit/phpunit/phpunit']),
    'contract-74' => array_merge($php, [$root . '/vendor/phpunit/phpunit/phpunit', '--testsuite', 'Contract']),
    'concurrency-74' => array_merge($php, [$root . '/tests/Concurrency/run.php']),
    'analysis-74' => array_merge($php, [$root . '/vendor/phpstan/phpstan/phpstan', 'analyse', '--memory-limit=512M', '--no-progress']),
];
if (PHP_OS_FAMILY === 'Windows') {
    foreach (['81' => '8.1', '85' => '8.5'] as $id => $version) {
        $commands['unit-' . $id] = ['C:/usr/php/' . $version . '/php.exe', '-c', $root . '/.local/php' . $id . '.ini', $root . '/vendor/phpunit/phpunit/phpunit'];
    }
    $commands['concurrency-85'] = ['C:/usr/php/8.5/php.exe', '-c', $root . '/.local/php85.ini', $root . '/tests/Concurrency/run.php'];
}
if (is_file($root . '/.local/lowest/bootstrap.php')) {
    $commands['unit-74-sdk400'] = array_merge($php, [$root . '/.local/lowest/vendor/phpunit/phpunit/phpunit', '--bootstrap', $root . '/.local/lowest/bootstrap.php']);
}
if (!in_array('--quick', $argv, true)) { $commands['benchmark-74'] = array_merge($php, [$root . '/tools/benchmark.php']); }
$failures = 0;
foreach ($commands as $name => $command) {
    echo $name . "\n"; flush();
    $log = $evidence . '/' . $name . '.txt';
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start ' . $name); }
    fclose($pipes[0]);
    $exit = proc_close($p);
    echo $name . ' exit=' . $exit . "\n"; flush();
    if ($exit !== 0) { ++$failures; }
}
foreach (glob($root . '/.cache/concurrency-*-*.json') ?: [] as $file) { copy($file, $evidence . '/' . basename($file)); }
foreach (glob($root . '/.cache/benchmark-*.json') ?: [] as $file) { copy($file, $evidence . '/' . basename($file)); }
if (is_file($root . '/.local/lowest/composer.lock')) { copy($root . '/.local/lowest/composer.lock', $evidence . '/php74-sdk400.lock'); }
exit($failures === 0 ? 0 : 1);
