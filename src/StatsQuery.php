<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe;

use Kgkg\SentryDedupe\Exception\ConfigurationException;

final class StatsQuery
{
    /** @var array<string, mixed> */
    private array $values;
    /** @param array<string, mixed> $values */
    private function __construct(array $values) { $this->values = json_decode(Config::canonical($values), true, 512, JSON_THROW_ON_ERROR); }
    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $v = array_replace(['limit' => 20, 'sort' => 'total_seen', 'fingerprint' => null, 'from' => null, 'to' => null], $values);
        if (array_diff_key($values, array_flip(['limit', 'sort', 'fingerprint', 'from', 'to']))) { throw new ConfigurationException('Unknown stats option'); }
        Config::integer($v['limit'], 1, 1000);
        if (!in_array($v['sort'], ['total_seen', 'last_seen_at', 'fingerprint'], true)) { throw new ConfigurationException('Invalid sort'); }
        if ($v['fingerprint'] !== null && (!is_string($v['fingerprint']) || !preg_match('/^[a-f0-9]{64}$/D', $v['fingerprint']))) { throw new ConfigurationException('Invalid fingerprint'); }
        if (($v['from'] === null) !== ($v['to'] === null)) { throw new ConfigurationException('Both range bounds required'); }
        if ($v['from'] !== null) {
            Config::integer($v['from'], 0, PHP_INT_MAX);
            Config::integer($v['to'], 1, PHP_INT_MAX);
            if ($v['from'] >= $v['to']) { throw new ConfigurationException('Empty/reversed range'); }
        }
        return new self($v);
    }
    /** @return array<string, mixed> */
    public function toArray(): array { return $this->values; }
}
