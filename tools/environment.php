<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

$data = ['php' => PHP_VERSION, 'int_size' => PHP_INT_SIZE, 'os' => php_uname(),
    'sdk' => Composer\InstalledVersions::getPrettyVersion('sentry/sentry'),
    'sqlite' => extension_loaded('sqlite3') ? SQLite3::version() : null,
    'extensions' => get_loaded_extensions(), 'cwd' => getcwd()];
if (!is_dir(dirname(__DIR__) . '/.cache')) { mkdir(dirname(__DIR__) . '/.cache', 0700, true); }
file_put_contents(dirname(__DIR__) . '/.cache/environment.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
