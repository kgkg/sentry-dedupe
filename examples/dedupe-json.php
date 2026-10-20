<?php
declare(strict_types=1);

use Kgkg\SentryDedupe\Config;

return Config::fromArray([
    'namespace' => 'orders:sentry-project-123:production',
    'backend' => 'json',
    'json' => ['directory' => dirname(__DIR__) . '/var/json'],
    'fingerprint' => ['project_root' => dirname(__DIR__)],
]);
