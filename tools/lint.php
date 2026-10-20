<?php
declare(strict_types=1);

$paths = ['src', 'tests', 'examples', 'tools', 'bin'];
$failures = 0;
foreach ($paths as $path) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' && $file->getFilename() !== 'sentry-dedupe') { continue; }
        $command = [PHP_BINARY, '-n', '-l', $file->getPathname()];
        $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($p) !== 0) { ++$failures; echo $output; }
    }
}
echo $failures === 0 ? "All PHP sources parse on " . PHP_VERSION . "\n" : "Lint failed\n";
exit($failures === 0 ? 0 : 1);
