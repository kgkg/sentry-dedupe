<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Fixtures;

use Kgkg\SentryDedupe\Config;

final class Stores
{
    /** @param array<string, mixed> $overrides */
    public static function config(string $backend, array $overrides = [], ?string $root = null): Config
    {
        $root = $root ?? dirname(__DIR__, 2) . '/.cache/tests/' . bin2hex(random_bytes(12));
        $root = str_replace('\\', '/', $root);
        return Config::fromArray(array_replace_recursive([
            'namespace' => 'test:project:production', 'backend' => $backend,
            'sqlite' => ['path' => $root . '/state.sqlite'], 'json' => ['directory' => $root . '/json'],
            'lock_timeout_ms' => 5000,
        ], $overrides));
    }
}
