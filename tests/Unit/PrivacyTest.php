<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Unit;

use Kgkg\SentryDedupe\Factory;
use Kgkg\SentryDedupe\Tests\Fixtures\{Stores, FakeClock};
use Sentry\Event;
use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    public function testPayloadDoesNotEnterStateOrDiagnostics(): void
    {
        $c = Stores::config('json'); $clock = new FakeClock();
        Factory::store($c, $clock)->initialize();
        $diagnostics = [];
        $d = Factory::create($c, $clock, null, static function (string $reason, array $metadata) use (&$diagnostics): void { $diagnostics[] = [$reason, $metadata]; });
        $e = Event::createEvent()->setMessage('secret-message-token')->setExtra(['password' => 'secret-password-token']);
        self::assertSame($e, $d($e));
        $path = $c->get('json')['directory'] . '/' . hash('sha256', $c->get('namespace')) . '/state.json';
        $state = file_get_contents($path);
        self::assertStringNotContainsString('secret-', $state);
        self::assertStringNotContainsString('password', $state);
        self::assertSame([['admit', ['backend' => 'json']]], $diagnostics);
    }
}
