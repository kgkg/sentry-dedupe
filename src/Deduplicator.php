<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe;

use Kgkg\SentryDedupe\Storage\StoreInterface;
use Sentry\{Event, EventHint};

final class Deduplicator
{
    private Config $config;
    private StoreInterface $store;
    private FingerprintStrategyInterface $strategy;
    /** @var callable|null */
    private $diagnostic;
    private bool $active = false;
    /** @var array<string, int> */
    private array $diagnosed = [];
    public function __construct(Config $config, StoreInterface $store, FingerprintStrategyInterface $strategy, ?callable $diagnostic = null)
    {
        $this->config = $config; $this->store = $store; $this->strategy = $strategy; $this->diagnostic = $diagnostic;
    }
    public function __invoke(Event $event, ?EventHint $hint = null): ?Event { return ($this->wrapBeforeSend())($event, $hint); }
    public function wrapBeforeSend(?callable $previous = null): callable
    {
        return function (Event $event, ?EventHint $hint = null) use ($previous): ?Event {
            if ($this->active) { return null; }
            $this->active = true;
            try {
                if ($previous !== null) {
                    try { $result = $previous($event, $hint); }
                    catch (\Throwable $e) { $this->diagnose(Decision::CALLBACK_ERROR); return null; }
                    if ($result === null) { return null; }
                    if (!$result instanceof Event) { $this->diagnose(Decision::CALLBACK_ERROR); return null; }
                    $event = $result;
                }
                $decision = $this->process($event, $hint);
                if (!$decision->isAllowed()) { return null; }
                if ($this->config->get('event_context')) {
                    if (array_key_exists('sentry_dedupe', $event->getContexts())) { $this->diagnose('context_conflict'); }
                    else {
                        $data = $decision->toArray();
                        unset($data['reason'], $data['effective_at'], $data['last_admitted_at']);
                        $event->setContext('sentry_dedupe', $data + ['algorithm' => 'fp-v1', 'interval_seconds' => $this->config->get('interval_seconds')]);
                    }
                }
                return $event;
            } catch (\Throwable $e) { $this->diagnose(Decision::CALLBACK_ERROR); return null; }
            finally { $this->active = false; }
        };
    }
    public function evaluate(Event $event, ?EventHint $hint = null): Decision
    {
        if ($this->active) { return new Decision(Decision::REENTRANT); }
        $this->active = true;
        try { return $this->process($event, $hint); }
        finally { $this->active = false; }
    }
    private function process(Event $event, ?EventHint $hint): Decision
    {
        try {
            $fingerprint = $this->strategy->fingerprint($event, $hint);
            if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) { throw new \UnexpectedValueException(); }
        } catch (\Throwable $e) { $this->diagnose(Decision::FINGERPRINT_ERROR); return new Decision(Decision::FINGERPRINT_ERROR); }
        try { $decision = $this->store->observe($fingerprint); }
        catch (\Throwable $e) { $decision = new Decision(Decision::STORAGE_ERROR, $fingerprint); }
        $this->diagnose($decision->reason());
        return $decision;
    }
    private function diagnose(string $reason): void
    {
        if ($this->diagnostic === null) { return; }
        $now = hrtime(true);
        if (isset($this->diagnosed[$reason]) && $now - $this->diagnosed[$reason] < 60000000000) { return; }
        $this->diagnosed[$reason] = $now;
        try { ($this->diagnostic)($reason, ['backend' => $this->config->get('backend')]); }
        catch (\Throwable $ignored) {}
    }
}
