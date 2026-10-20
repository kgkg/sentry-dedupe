<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Fingerprint;

use Kgkg\SentryDedupe\{Config, FingerprintStrategyInterface};
use Kgkg\SentryDedupe\Exception\FingerprintException;
use Sentry\{Event, EventHint, Stacktrace, Frame};

final class DefaultFingerprintStrategy implements FingerprintStrategyInterface
{
    private Config $config;
    /** @var array<string, mixed> */
    private array $options;
    private int $bytes = 0;
    public function __construct(Config $config) { $this->config = $config; $this->options = $config->get('fingerprint'); }

    public function fingerprint(Event $event, ?EventHint $hint = null): string
    {
        $this->bytes = 0;
        $custom = $this->customComponents($event->getFingerprint());
        $automatic = null;
        $identity = [];
        foreach ($custom as $component) {
            if ($component === '{{ default }}') {
                if ($automatic === null) { $automatic = $this->automatic($event); }
                $identity[] = ['automatic' => $automatic];
            } else { $identity[] = ['custom' => $this->text($component, false)]; }
        }
        if (!$custom) { $identity = ['automatic' => $this->automatic($event)]; }
        $input = ['algorithm' => 'fp-v1', 'namespace' => $this->config->get('namespace'),
            'environment' => $this->text($event->getEnvironment() ?? '', false), 'type' => (string) $event->getType(), 'identity' => $identity];
        if ($this->options['include_release']) { $input['release'] = $this->text($event->getRelease() ?? '', false); }
        $json = Config::canonical($input);
        if (strlen($json) > 262144) { throw new FingerprintException('identity_size'); }
        return hash('sha256', $json);
    }
    /**
     * Validate SDK input at runtime: its string[] PHPDoc is not enforced by PHP.
     * @param array<mixed> $components
     * @return list<string>
     */
    private function customComponents(array $components): array
    {
        if (count($components) > 16 || ($components !== [] && array_keys($components) !== range(0, count($components) - 1))) { throw new FingerprintException('custom_components'); }
        $result = [];
        foreach ($components as $component) {
            if (!is_string($component) || strlen($component) > 1024) { throw new FingerprintException('custom_component_size'); }
            $result[] = $component;
        }
        return $result;
    }
    /** @return array<string, mixed> */
    private function automatic(Event $event): array
    {
        $exceptions = $event->getExceptions();
        if ($exceptions) {
            if (count($exceptions) > 4096) { throw new FingerprintException('chain_size'); }
            $chain = [];
            $full = [];
            foreach ($exceptions as $i => $exception) {
                $type = $this->text($exception->getType(), false);
                $message = $this->text($exception->getValue());
                $full[] = [$type, $message];
                if ($i < 8) {
                    $item = ['type' => $type, 'message' => $message, 'frames' => $this->frames($exception->getStacktrace())];
                    $mechanism = $exception->getMechanism();
                    if ($mechanism !== null) {
                        $data = $mechanism->getData();
                        foreach (['errno', 'severity', 'code'] as $key) {
                            if (isset($data[$key]) && is_int($data[$key])) { $item[$key] = $data[$key]; }
                        }
                    }
                    $chain[] = $item;
                }
            }
            return ['exceptions' => $chain, 'level' => $event->getLevel() === null ? null : (string) $event->getLevel()]
                + (count($exceptions) > 8 ? ['chain_hash' => hash('sha256', Config::canonical($full))] : []);
        }
        $template = $event->getMessage();
        $hasTemplate = $template !== null && $template !== '' && ($event->getMessageParams() !== [] || $event->getMessageFormatted() !== null);
        $message = $template !== null && $template !== '' ? $template : $event->getMessageFormatted();
        $frames = $this->frames($event->getStacktrace());
        if (($message === null || $message === '') && !$frames) { throw new FingerprintException('empty_identity'); }
        return ['message' => $this->text($message ?? '', !$hasTemplate), 'template' => $hasTemplate,
            'level' => $event->getLevel() === null ? null : (string) $event->getLevel(),
            'logger' => $this->text($event->getLogger() ?? '', false), 'frames' => $frames];
    }
    /** @return array<int, array<string, mixed>> */
    private function frames(?Stacktrace $stack): array
    {
        if ($stack === null) { return []; }
        $frames = $stack->getFrames();
        if (count($frames) > 8192) { throw new FingerprintException('trace_size'); }
        // SDK stores caller -> callee; local identity starts at the failure.
        $frames = array_reverse($frames);
        $app = array_filter($frames, static function (Frame $frame): bool { return $frame->isInApp(); });
        $result = [];
        foreach (array_slice($app ?: $frames, 0, $this->options['max_frames']) as $frame) {
            if (($frame->getAbsoluteFilePath() ?? $frame->getFile()) === '' && ($frame->getFunctionName() ?? '') === '') { continue; }
            $r = ['file' => $this->text($frame->getAbsoluteFilePath() ?? $frame->getFile()),
                'function' => $this->text($frame->getFunctionName() ?? '')];
            if ($this->options['include_line']) { $r['line'] = $frame->getLine(); }
            $result[] = $r;
        }
        return $result;
    }
    private function text(string $value, bool $normalize = true): string
    {
        $length = strlen($value);
        if ($length > 65536 || $this->bytes > 262144 - $length) { throw new FingerprintException('text_size'); }
        $this->bytes += $length;
        if (!preg_match('//u', $value)) { throw new FingerprintException('invalid_utf8'); }
        if (!$normalize) { return $value; }
        $value = str_replace(["\r\n", "\r", '\\'], ["\n", "\n", '/'], $value);
        $value = $this->replace('/[\t\x0b\x0c ]+/', ' ', $value);
        $prefixes = $this->options['deployment_prefixes'];
        if ($this->options['project_root'] !== null) { $prefixes[$this->options['project_root']] = '<app>'; }
        uksort($prefixes, static function (string $a, string $b): int { return (strlen($b) <=> strlen($a)) ?: strcmp($a, $b); });
        foreach ($prefixes as $path => $token) {
            $value = $this->replace('~(?<![A-Za-z0-9_./:-])' . preg_quote(rtrim($path, '/'), '~') . '(?=/|$)~', $token, $value);
        }
        $value = $this->replace('~(https?://[^\s?]+)\?[^\s]*~', '$1', $value);
        $value = $this->replace('/(?<![A-Za-z0-9_])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![A-Za-z0-9_])/i', '<uuid>', $value);
        $value = $this->replace('/(?<![A-Za-z0-9_])0x[0-9a-f]+(?![A-Za-z0-9_])/i', '<addr>', $value);
        foreach ($this->options['normalization_rules'] as $rule) { $value = $this->replace($rule['pattern'], $rule['replacement'], $value); }
        return $value;
    }
    private function replace(string $pattern, string $replacement, string $value): string
    {
        set_error_handler(static function (): bool { return true; });
        try { $result = preg_replace($pattern, $replacement, $value); }
        finally { restore_error_handler(); }
        if ($result === null || strlen($result) > 262144 || !preg_match('//u', $result)) { throw new FingerprintException('normalization_failure'); }
        return $result;
    }
}
