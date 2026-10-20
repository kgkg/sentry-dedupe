<?php
declare(strict_types=1);

use Kgkg\SentryDedupe\Config;

return Config::fromArray([
    'namespace' => 'orders:sentry-project-123:production',
    'backend' => 'sqlite',
    'sqlite' => ['path' => dirname(__DIR__) . '/var/sqlite/state.sqlite'],
    'fingerprint' => ['project_root' => dirname(__DIR__)],
]);
