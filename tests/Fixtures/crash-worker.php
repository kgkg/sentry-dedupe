<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Kgkg\SentryDedupe\{Config, Factory};
use Kgkg\SentryDedupe\Tests\Fixtures\{FakeClock, Faults};

[$script, $file, $phase] = $argv;
$c = Config::fromArray(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
Faults::$checkpoint = static function (string $at) use ($phase): void {
    if ($at === $phase) {
        echo "checkpoint\n"; flush();
        // Parent terminates this process without PHP shutdown/finally execution.
        fgets(STDIN);
    }
};
Factory::store($c, new FakeClock())->observe(hash('sha256', 'same'));
