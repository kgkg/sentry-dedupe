<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require __DIR__ . '/dedupe-sqlite.php';
$dedupe = \Kgkg\SentryDedupe\Factory::create($config);

\Sentry\init([
    'dsn' => getenv('SENTRY_DSN') ?: null,
    'environment' => 'production',
    'sample_rate' => 1.0,
    'before_send' => $dedupe->wrapBeforeSend(),
]);
