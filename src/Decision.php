<?php
declare(strict_types=1);

namespace Kgkg\SentryDedupe;

final class Decision
{
    public const ADMIT = 'admit';
    public const DUPLICATE = 'duplicate';
    public const GLOBAL_LIMIT = 'global_limit';
    public const CAPACITY = 'capacity';
    public const STORAGE_ERROR = 'storage_error';
    public const FINGERPRINT_ERROR = 'fingerprint_error';
    public const CALLBACK_ERROR = 'callback_error';
    public const REENTRANT = 'reentrant';
    private string $reason;
    private ?string $fingerprint;
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(string $reason, ?string $fingerprint = null, array $data = [])
    {
        $this->reason = $reason;
        $this->fingerprint = $fingerprint;
        $this->data = $data;
    }
    public function isAllowed(): bool { return $this->reason === self::ADMIT; }
    public function reason(): string { return $this->reason; }
    public function fingerprint(): ?string { return $this->fingerprint; }
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'fingerprint' => $this->fingerprint] + $this->data + array_fill_keys([
            'effective_at', 'total_seen', 'total_admitted', 'suppressed_since_previous_admission',
            'first_seen_at', 'last_seen_at', 'last_admitted_at',
        ], null);
    }
}
