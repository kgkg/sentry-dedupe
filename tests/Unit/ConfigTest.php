<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Unit;

use Kgkg\SentryDedupe\Config;
use Kgkg\SentryDedupe\Exception\ConfigurationException;
use Kgkg\SentryDedupe\Tests\Fixtures\Stores;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @return array<int, array{array<string, mixed>}> */
    public function invalid(): array
    {
        return array_map(static function (array $v): array { return [$v]; }, [
            ['unknown' => 1], ['namespace' => ''], ['namespace' => "bad\0value"], ['namespace' => str_repeat('a', 201)],
            ['backend' => 'pdo'], ['interval_seconds' => '3600'], ['interval_seconds' => 0], ['interval_seconds' => 31536001],
            ['global_limit' => ['max_events' => 0]], ['global_limit' => ['max_events' => null]], ['global_limit' => ['window_seconds' => 0]],
            ['global_limit' => null], ['json' => ['directory' => 'relative']], ['json' => ['directory' => 'https://host/a']],
            ['json' => ['directory' => '/a/../b']], ['json' => ['directory' => '//host/share']], ['json' => ['max_state_bytes' => 1024]],
            ['sqlite' => ['synchronous' => 'NORMAL']], ['sqlite' => ['journal_mode' => 'MEMORY']], ['lock_timeout_ms' => 5001],
            ['retention_seconds' => 3599], ['max_fingerprints' => 100001], ['event_context' => 1],
            ['fingerprint' => ['max_frames' => 21]], ['fingerprint' => ['unknown' => true]], ['fingerprint' => ['include_line' => null]],
            ['fingerprint' => ['normalization_rules' => [['pattern' => '/(/', 'replacement' => 'x']]]],
        ]);
    }
    /** @dataProvider invalid @param array<string, mixed> $values */
    public function testInvalidConfig(array $values): void
    {
        $this->expectException(ConfigurationException::class);
        Stores::config('json', $values);
    }
    public function testPolicyHashIgnoresOperationalLimitsAndMapOrder(): void
    {
        $c = Stores::config('json');
        $v = $c->toSafeArray();
        $v['lock_timeout_ms'] = 1; $v['max_fingerprints'] = 1;
        self::assertSame($c->policyHash(), Config::fromArray($v)->policyHash());
        $v['interval_seconds'] = 2;
        self::assertNotSame($c->policyHash(), Config::fromArray($v)->policyHash());
        self::assertSame(Config::canonical(['b' => 1, 'a' => [2, 1]]), Config::canonical(['a' => [2, 1], 'b' => 1]));
        self::assertNotSame(Config::canonical([1, 2]), Config::canonical([2, 1]));
        $seconds = 3600; $replacement = 'initial';
        $v = $c->toSafeArray();
        $v['interval_seconds'] = &$seconds;
        $v['fingerprint']['normalization_rules'] = [['pattern' => '/id=[0-9]+/', 'replacement' => &$replacement]];
        $immutable = Config::fromArray($v);
        $seconds = 0; $replacement = 'changed';
        self::assertSame(3600, $immutable->get('interval_seconds'));
        self::assertSame('initial', $immutable->get('fingerprint')['normalization_rules'][0]['replacement']);
    }
}
