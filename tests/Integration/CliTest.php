<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Integration;

use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, Process};
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testCommandsAndExitCodes(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/sentry-dedupe';
        foreach (['--help', '--version'] as $cmd) {
            [$exit, $out, $err] = Process::run([$bin, $cmd]);
            self::assertSame(0, $exit, $err); self::assertNotEmpty($out); self::assertSame('', $err);
        }
        $backend = getenv('SENTRY_DEDUPE_BACKEND') ?: 'json';
        $c = Stores::config($backend);
        $root = dirname($c->get('sqlite')['path']);
        mkdir($root, 0700, true);
        $file = $root . '/config.php';
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\nreturn \\Kgkg\\SentryDedupe\\Config::fromArray(" . var_export($c->toSafeArray(), true) . ');');
        [$exit, $out] = Process::run([$bin, 'stats', '--config=' . $file, '--format=json']);
        self::assertSame(1, $exit); self::assertSame('', $out);
        foreach (['init', 'stats', 'doctor', 'cleanup'] as $cmd) {
            [$exit, $out, $err] = Process::run([$bin, $cmd, '--config=' . $file, '--format=json']);
            self::assertSame(0, $exit, $cmd . ': ' . $err . $out);
            self::assertIsArray(json_decode($out, true, 512, JSON_THROW_ON_ERROR));
            self::assertSame('', $err);
        }
        [$exit, $out, $err] = Process::run([$bin, 'cleanup', '--config=' . $file, '--format=json', '--dry-run', '--limit=1']);
        self::assertSame(0, $exit, $err); self::assertTrue(json_decode($out, true)['dry_run']);
        [$exit, $out] = Process::run([$bin, 'stats', '--config=' . $file, '--limit=0']);
        self::assertSame(2, $exit); self::assertSame('', $out);
        [$exit] = Process::run([$bin, 'policy:apply', '--config=' . $file, '--expect-policy-hash=' . str_repeat('0', 64)]);
        self::assertSame(3, $exit);
        [$exit] = Process::run([$bin, 'policy:apply', '--config=' . $file, '--expect-policy-hash=' . $c->policyHash()]);
        self::assertSame(0, $exit);
        [$exit] = Process::run([$bin, 'stats', '--config=' . $file, '--from=1', '--to=3600']);
        self::assertSame(2, $exit);
    }
}
