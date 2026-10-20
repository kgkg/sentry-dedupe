<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Storage;

use Kgkg\SentryDedupe\Tests\Fixtures\Faults;

// Test-only PHP namespace seams; not shipped through the production autoloader.
function fwrite($stream, string $data)
{
    Faults::hit('before_write');
    if (Faults::$operation === 'write') { return false; }
    if (Faults::$operation === 'zero_write') { return 0; }
    $n = \fwrite($stream, Faults::$operation === 'short_write' ? substr($data, 0, 7) : $data);
    Faults::hit('after_write');
    return $n;
}
function fflush($stream): bool
{
    if (Faults::$operation === 'flush') { return false; }
    $result = \fflush($stream);
    Faults::hit('after_flush');
    return $result;
}
function rename(string $from, string $to): bool
{
    Faults::hit('before_rename');
    if (Faults::$operation === 'rename') { return false; }
    $result = \rename($from, $to);
    Faults::hit('after_rename');
    return $result;
}
