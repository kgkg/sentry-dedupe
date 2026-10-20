<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Tests\Fixtures;

final class Process
{
    /** @var string[]|null */
    private static ?array $builtins = null;
    /** @param string[] $args @return string[] */
    public static function command(array $args): array
    {
        $php = [PHP_BINARY];
        if (php_ini_loaded_file()) { $php = array_merge($php, ['-c', php_ini_loaded_file()]); }
        else {
            $php[] = '-n';
            $php = array_merge($php, ['-d', 'extension_dir=' . ini_get('extension_dir')]);
            if (self::$builtins === null) {
                $probe = proc_open([PHP_BINARY, '-n', '-r', 'echo json_encode(get_loaded_extensions());'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($probe)) { throw new \RuntimeException('Cannot inspect built-in PHP extensions'); }
                fclose($probePipes[0]);
                $json = stream_get_contents($probePipes[1]);
                $error = stream_get_contents($probePipes[2]);
                fclose($probePipes[1]); fclose($probePipes[2]);
                if (proc_close($probe) !== 0 || $error !== '') { throw new \RuntimeException('Built-in extension probe failed'); }
                self::$builtins = array_map('strtolower', json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            }
            foreach (['curl', 'mbstring', 'sqlite3', 'openssl', 'dom', 'xml', 'xmlwriter', 'tokenizer', 'json', 'simplexml'] as $ext) {
                $binary = rtrim((string) ini_get('extension_dir'), '/\\') . '/' . (PHP_OS_FAMILY === 'Windows' ? 'php_' . $ext . '.dll' : $ext . '.so');
                if (extension_loaded($ext) && !in_array($ext, self::$builtins, true) && is_file($binary)) { $php = array_merge($php, ['-d', 'extension=' . $ext]); }
            }
        }
        return array_merge($php, $args);
    }
    /** @param string[] $args @return array{int, string, string} */
    public static function run(array $args): array
    {
        $p = proc_open(self::command($args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) { throw new \RuntimeException('Cannot start PHP'); }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $out, $err];
    }
}
