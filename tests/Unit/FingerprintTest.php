<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Unit;

use Kgkg\SentryDedupe\Fingerprint\DefaultFingerprintStrategy;
use Kgkg\SentryDedupe\Exception\FingerprintException;
use Kgkg\SentryDedupe\Tests\Fixtures\Stores;
use Sentry\{Event, ExceptionDataBag, Frame, Stacktrace, Severity};
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    private function strategy(array $options = []): DefaultFingerprintStrategy { return new DefaultFingerprintStrategy(Stores::config('json', ['fingerprint' => $options])); }
    private function message(string $text): Event { return Event::createEvent()->setMessage($text); }
    public function testNormalizationAndMeaningfulNumbers(): void
    {
        $s = $this->strategy(['project_root' => 'C:/app']);
        self::assertSame($s->fingerprint($this->message("C:\\app\\src\\x.php\r\nvalue\t 0xABCD 550e8400-e29b-41d4-a716-446655440000")),
            $s->fingerprint($this->message("<app>/src/x.php\nvalue 0x12 550e8400-e29b-41d4-a716-446655440001")));
        foreach ([['HTTP 404', 'HTTP 500'], ['SQLSTATE 23000', 'SQLSTATE 42000'], ['port 80', 'port 443'],
            ['A', 'a'], ['C:/app-other/x', '<app>/x'], ['foo0x123', 'foo0x456']] as [$a, $b]) {
            self::assertNotSame($s->fingerprint($this->message($a)), $s->fingerprint($this->message($b)));
        }
    }
    public function testCustomComponentsAndDefaultTokens(): void
    {
        $s = $this->strategy();
        $a = $this->message('a')->setFingerprint(['stable']);
        $b = $this->message('b')->setFingerprint(['stable']);
        self::assertSame($s->fingerprint($a), $s->fingerprint($b));
        $a->setFingerprint(['{{ default }}']); $b->setFingerprint(['{{ default }}']);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
        $a->setFingerprint(['0x123']); $b->setFingerprint(['0x456']);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
        $a->setFingerprint(['a', 'b']); $b->setFingerprint(['b', 'a']);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
        self::assertSame(['a', 'b'], $a->getFingerprint());
    }
    public function testTemplateExcludesParameterValuesReleaseAndEventTimestamp(): void
    {
        $s = $this->strategy();
        $a = Event::createEvent()->setMessage('order %s failed', ['123'], 'order 123 failed');
        $b = Event::createEvent()->setMessage('order %s failed', ['456'], 'order 456 failed');
        $a->setRelease('v1')->setTimestamp(1.0);
        $b->setRelease('v2')->setTimestamp(2.0);
        self::assertSame($s->fingerprint($a), $s->fingerprint($b));
        $b->setEnvironment('staging');
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
        $b->setEnvironment(null);
        self::assertNotSame($this->strategy(['include_release' => true])->fingerprint($a), $this->strategy(['include_release' => true])->fingerprint($b));
    }
    public function testFramesUseFailureFirstAndPreferApplicationFrames(): void
    {
        $s = $this->strategy(['max_frames' => 1]);
        $caller = new Frame('caller', '/app/caller.php', 10);
        $failure = (new Frame('fail', '/app/fail.php', 20))->setIsInApp(true);
        $vendor = (new Frame('vendor', '/vendor/x.php', 30))->setIsInApp(false);
        $a = Event::createEvent()->setExceptions([new ExceptionDataBag(new \RuntimeException('bad'), new Stacktrace([$caller, $failure, $vendor]))]);
        $b = Event::createEvent()->setExceptions([new ExceptionDataBag(new \RuntimeException('bad'), new Stacktrace([$failure]))]);
        self::assertSame($s->fingerprint($a), $s->fingerprint($b));
        $b->setExceptions([new ExceptionDataBag(new \LogicException('bad'), new Stacktrace([$failure]))]);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
        $b->setExceptions([new ExceptionDataBag(new \RuntimeException('bad'), new Stacktrace([new Frame('fail', '/app/fail.php', 21)]))]);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }
    public function testLongChainsIncludeOmittedMessages(): void
    {
        $s = $this->strategy();
        $chain = array_fill(0, 9, new ExceptionDataBag(new \RuntimeException('a')));
        $a = Event::createEvent()->setExceptions($chain);
        $chain[8] = new ExceptionDataBag(new \RuntimeException('b'));
        $b = Event::createEvent()->setExceptions($chain);
        self::assertNotSame($s->fingerprint($a), $s->fingerprint($b));
    }
    public function testOrderedDomainRules(): void
    {
        $s = $this->strategy(['normalization_rules' => [['pattern' => '/order_id=[0-9]+/', 'replacement' => 'order_id=<id>']]]);
        self::assertSame($s->fingerprint($this->message('order_id=123 HTTP 500')), $s->fingerprint($this->message('order_id=456 HTTP 500')));
    }
    public function testFormattedOnlyMessageIsUsableButEmptyFramesAreNot(): void
    {
        $s = $this->strategy();
        self::assertSame($s->fingerprint($this->message('formatted')), $s->fingerprint(Event::createEvent()->setMessage('', [], 'formatted')));
        $this->expectException(FingerprintException::class);
        $s->fingerprint(Event::createEvent()->setStacktrace(new Stacktrace([new Frame(null, '', 0)])));
    }
    /** @return array<string, array{string}> */
    public function invalidMessages(): array { return ['empty' => [''], 'utf8' => ["\xff"], 'large' => [str_repeat('x', 65537)]]; }
    /** @dataProvider invalidMessages */
    public function testInvalidMessages(string $text): void
    {
        $this->expectException(FingerprintException::class);
        $this->strategy()->fingerprint($this->message($text));
    }
    public function testPcreFailureIsDenied(): void
    {
        $s = $this->strategy(['normalization_rules' => [['pattern' => '/^(a+)+$/', 'replacement' => 'x']]]);
        $old = ini_set('pcre.backtrack_limit', '100');
        try {
            $this->expectException(FingerprintException::class);
            $s->fingerprint($this->message(str_repeat('a', 1000) . 'b'));
        } finally { ini_set('pcre.backtrack_limit', $old); }
    }
}
