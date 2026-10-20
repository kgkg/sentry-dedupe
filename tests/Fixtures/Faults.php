<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Fixtures;

final class Faults
{
    public static ?string $operation = null;
    /** @var callable|null */
    public static $checkpoint = null;
    public static int $calls = 0;
    public static function hit(string $operation): void
    {
        if (self::$checkpoint !== null) { (self::$checkpoint)($operation); }
    }
}
