<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe;

use Kgkg\SentryDedupe\Exception\ConfigurationException;

final class CleanupOptions
{
    private bool $dryRun;
    private int $limit;
    private function __construct(bool $dryRun, int $limit) { $this->dryRun = $dryRun; $this->limit = $limit; }
    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        if (array_diff_key($values, ['dry_run' => true, 'limit' => true])) { throw new ConfigurationException('Unknown cleanup option'); }
        $v = array_replace(['dry_run' => false, 'limit' => 1000], $values);
        if (!is_bool($v['dry_run'])) { throw new ConfigurationException('Expected dry_run boolean'); }
        Config::integer($v['limit'], 1, 100000);
        return new self($v['dry_run'], $v['limit']);
    }
    public function isDryRun(): bool { return $this->dryRun; }
    public function limit(): int { return $this->limit; }
}
