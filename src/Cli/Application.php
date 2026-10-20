<?php
declare(strict_types=1);
namespace Kgkg\SentryDedupe\Cli;

use Kgkg\SentryDedupe\{Config, Factory, StatsQuery, CleanupOptions};
use Kgkg\SentryDedupe\Exception\{ConfigurationException, PolicyException, StorageException};

final class Application
{
    public const VERSION = '0.1.0-dev';
    /** @param string[] $arguments */
    public function run(array $arguments): int
    {
        try {
            $command = array_shift($arguments) ?? 'help';
            if (in_array($command, ['help', '--help', '-h'], true)) {
                echo "sentry-dedupe " . self::VERSION . "\nCommands: init, doctor, stats, cleanup, policy:apply\n"
                    . "Usage: sentry-dedupe COMMAND --config=/absolute/config.php [--format=json|table]\n"
                    . "stats: --limit=20 --sort=total_seen|last_seen_at|fingerprint --fingerprint=HASH --from=UNIX --to=UNIX\n"
                    . "cleanup: --dry-run --limit=1000\npolicy:apply: --expect-policy-hash=HASH (stop old workers first)\n";
                return 0;
            }
            if (in_array($command, ['version', '--version'], true)) { echo self::VERSION . "\n"; return 0; }
            $allowed = ['init' => [], 'doctor' => [], 'stats' => ['limit', 'sort', 'fingerprint', 'from', 'to'],
                'cleanup' => ['dry-run', 'limit'], 'policy:apply' => ['expect-policy-hash']];
            if (!isset($allowed[$command])) { throw new ConfigurationException('Unknown command'); }
            $options = [];
            foreach ($arguments as $argument) {
                if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $argument, $match)) { throw new ConfigurationException('Use --key=value options'); }
                $key = $match[1];
                if (array_key_exists($key, $options) || !in_array($key, array_merge(['config', 'format'], $allowed[$command]), true)) { throw new ConfigurationException('Unknown/duplicate option'); }
                if ($key === 'dry-run') {
                    if (isset($match[2])) { throw new ConfigurationException('dry-run takes no value'); }
                    $options[$key] = true;
                } else {
                    if (!isset($match[2]) || $match[2] === '') { throw new ConfigurationException('Missing option value'); }
                    $options[$key] = $match[2];
                }
            }
            $format = $options['format'] ?? 'table';
            if (!in_array($format, ['json', 'table'], true)) { throw new ConfigurationException('Invalid format'); }
            if (!isset($options['config']) || !is_file($options['config'])) { throw new ConfigurationException('A trusted PHP config file is required'); }
            try { $config = require $options['config']; }
            catch (ConfigurationException $e) { throw $e; }
            catch (\Throwable $e) { throw new ConfigurationException('Cannot load config'); }
            if (!$config instanceof Config) { throw new ConfigurationException('Config file must return Config'); }
            $store = Factory::store($config);
            if ($command === 'init') { $store->initialize(); $data = ['initialized' => true, 'policy_hash' => $config->policyHash()]; }
            elseif ($command === 'doctor') {
                $report = $store->healthCheck();
                $this->output($report->toArray(), $format);
                if ($report->isHealthy()) { return 0; }
                return in_array($report->toArray()['checks']['state'], ['policy_mismatch', 'schema_mismatch'], true) ? 3 : 1;
            } elseif ($command === 'stats') {
                $query = array_intersect_key($options, array_flip($allowed['stats']));
                foreach (['limit', 'from', 'to'] as $key) { if (isset($query[$key])) { $query[$key] = $this->integer($query[$key]); } }
                $data = $store->stats(StatsQuery::fromArray($query))->toArray();
            } elseif ($command === 'cleanup') {
                $data = $store->cleanup(CleanupOptions::fromArray(['dry_run' => isset($options['dry-run']),
                    'limit' => isset($options['limit']) ? $this->integer($options['limit']) : 1000]))->toArray();
            } else {
                if (!isset($options['expect-policy-hash'])) { throw new ConfigurationException('Expected old policy hash required'); }
                $store->applyPolicy($options['expect-policy-hash']);
                $data = ['policy_applied' => true, 'policy_hash' => $config->policyHash()];
            }
            $this->output($data, $format);
            return 0;
        } catch (ConfigurationException $e) { fwrite(STDERR, $e->getMessage() . "\n"); return 2; }
        catch (PolicyException $e) { fwrite(STDERR, $e->getMessage() . "\n"); return 3; }
        catch (\Throwable $e) { fwrite(STDERR, ($e instanceof StorageException ? $e->getMessage() : 'Execution failed') . "\n"); return 1; }
    }
    private function integer(string $value): int
    {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) { throw new ConfigurationException('Invalid integer option'); }
        return (int) $value;
    }
    /** @param array<string, mixed> $data */
    private function output(array $data, string $format): void
    {
        if ($format === 'json') { echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; return; }
        if (isset($data['fingerprints'])) {
            echo 'Namespace: ' . $data['namespace'] . "\n";
            echo 'Lifetime: ' . json_encode($data['totals'], JSON_THROW_ON_ERROR) . "\n";
            echo 'Current bucket: ' . json_encode($data['current_bucket'], JSON_THROW_ON_ERROR) . "\n";
            echo "fingerprint                                                       seen admitted last_seen\n";
            foreach ($data['fingerprints'] as $record) { printf("%s %5d %8d %d\n", $record['fingerprint'], $record['total_seen'], $record['total_admitted'], $record['last_seen_at']); }
            foreach ($data['warnings'] as $warning) { echo 'Warning: ' . $warning . "\n"; }
            if (isset($data['bucket_range'])) { echo 'Range: ' . json_encode($data['bucket_range'], JSON_THROW_ON_ERROR) . "\n"; }
        } else { echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"; }
    }
}
