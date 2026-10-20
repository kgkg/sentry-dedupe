<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe;

final class HealthReport
{
    private bool $healthy;
    /** @var array<string, mixed> */
    private array $checks;
    /** @param array<string, mixed> $checks */
    public function __construct(bool $healthy, array $checks) { $this->healthy = $healthy; $this->checks = $checks; }
    public function isHealthy(): bool { return $this->healthy; }
    /** @return array<string, mixed> */
    public function toArray(): array { return ['format_version' => 1, 'healthy' => $this->healthy, 'checks' => $this->checks]; }
}
