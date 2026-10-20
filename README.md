# kgkg/sentry-dedupe

Persistent, fail-closed admission control for **Sentry PHP SDK 4.x**, on 64-bit PHP 7.4 and PHP 8.x. PHP namespace: `Kgkg\SentryDedupe`.

The first occurrence reserves one send attempt. The same fingerprint is suppressed for 3600 seconds. All fingerprints in one namespace also share a fixed UTC budget of 100 admissions per hour. Both defaults are configurable. Five hundred repeated errors produce one admission and 499 durable duplicate counts. A later occurrence is required to send again; there are no timer summaries, queues, or replays.

Licensed under the [MIT License](LICENSE). Copyright (c) 2026 Konrad Gałęzowski.

## Install and initialize

After publication on Packagist, install the package with Composer:

```sh
composer require kgkg/sentry-dedupe
```

Until publication, use a local Composer path repository. Runtime dependencies are `sentry/sentry:^4.0` and `ext-json`. The SQLite backend additionally needs **native `ext-sqlite3`**, suggested rather than required by Composer. JSON does not use a database extension. The selected SDK has its own platform requirements. PHP 7.4 compatibility does not imply security support for PHP 7.4.

Create a trusted PHP configuration file, outside any untrusted upload path:

```php
<?php
declare(strict_types=1);

use Kgkg\SentryDedupe\Config;

return Config::fromArray([
    'namespace' => 'orders:sentry-project-123:production',
    'backend' => 'sqlite',
    'sqlite' => ['path' => '/srv/private/sentry-dedupe/state.sqlite'],
    'fingerprint' => ['project_root' => '/srv/orders'],
]);
```

For JSON select `'backend' => 'json'` and `'json' => ['directory' => '/srv/private/sentry-dedupe/json']` instead. Examples for both backends are in `examples/`.

```sh
php vendor/bin/sentry-dedupe init --config=/srv/orders/dedupe.php
php vendor/bin/sentry-dedupe doctor --config=/srv/orders/dedupe.php --format=json
```

Run init **before traffic** as the service user. Construction performs no storage I/O and does not initialize storage. Runtime never creates a lost database, lock, namespace, or snapshot. Missing initialization, corrupt state, policy mismatch, lock timeout, storage failure, or fingerprint failure suppresses the event. No fail-open option or automatic backend fallback exists.

## Integrate with Sentry

```php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/dedupe.php';
$dedupe = \Kgkg\SentryDedupe\Factory::create($config);

\Sentry\init([
    'dsn' => getenv('SENTRY_DSN'),
    'environment' => 'production',
    'sample_rate' => 1.0,
    'before_send' => $dedupe->wrapBeforeSend($existingBeforeSend ?? null),
]);
```

Keep one instance per configuration/worker. Existing capture calls do not change. Do not install the same instance as both an event processor and `before_send`. Transactions, traces, profiles, metrics, logs and check-ins are outside this callback's scope.

The previous callback runs first. Its null result stops processing without touching storage. Its replacement Event is used for fingerprinting. Exceptions or wrong return types are suppressed. No rejecting callback runs after reservation. Each invocation observes an occurrence, including repeated use of the same Event object. `evaluate($event, $hint)` **persists an observation**; it is not a preview and must not run before `before_send` for the same occurrence.

An admission is a **reservation of a send attempt**, not proof of transport delivery. A failed transport or process death does not refund it. SDK sampling, processors and ignore rules can reject events before the callback; counters describe only observations reaching this callback. Use SDK `sample_rate: 1.0` for complete counts at this boundary.

Admitted events receive context `sentry_dedupe` with their hash, counters and admission-time pending suppression count. An existing context of that name is preserved. The first admitted event cannot describe future suppressions; use CLI for current statistics. The event's Sentry fingerprint is never modified.

## Configuration

Unknown keys and incorrect types throw `ConfigurationException`. Numeric strings require explicit conversion by the application. Configuration is immutable. `toSafeArray()` contains local configuration, including paths and regex rules, without event payloads or DSNs.

Only `namespace`, `backend`, and the selected backend's storage path are required. Set `fingerprint.project_root` explicitly for path normalization; the remaining settings can usually keep their defaults. With the defaults, the same fingerprint reserves at most one send attempt per 3600 seconds, and all fingerprints in a namespace share a budget of 100 attempts per fixed UTC hour.

### Application and storage

| Setting | Default / constraint | Purpose |
|---|---|---|
| `namespace` | Required, 1–200 bytes, UTF-8 without control characters | Application-defined name separating deduplication state, counters and quota, e.g. `shop:production`. It does not need to contain a Sentry project ID. Use different names for independent applications/environments, and the same name/configuration/store for cooperating workers. |
| `backend` | Required: `sqlite` or `json` | Selects the persistent storage implementation. Only the selected backend's storage path is required. |
| `sqlite.path` | Absolute local path without dot segments/stream wrappers | Location of the SQLite database. The service user needs access to the file and write access to its directory for SQLite sidecar files. Different namespaces may share one database, but still have separate quotas and counters. |
| `sqlite.journal_mode` | `WAL`; alternatively `DELETE` | SQLite journaling mode. `WAL` permits readers alongside a writer; `DELETE` uses a rollback journal. Writes still serialize in either mode. |
| `sqlite.synchronous` | `FULL`, required | SQLite synchronization level for durable commits, subject to filesystem/hardware guarantees. Weaker values are rejected. |
| `json.directory` | Absolute local path without dot segments/stream wrappers | Parent directory for JSON storage. Each namespace gets its own hashed subdirectory with a snapshot and lockfile. Unused with SQLite. |
| `json.max_state_bytes` | 16777216 bytes (16 MiB); range 1–256 MiB | Bounds the JSON snapshot size. Exceeding the bound causes a storage failure and suppresses the event rather than silently discarding history. Unused with SQLite. |

### Admission limits and maintenance

| Setting | Default / constraint | Purpose |
|---|---|---|
| `interval_seconds` | 3600 seconds; range 1–31,536,000 | Cooldown between admitted occurrences of the same fingerprint. Repeats during the cooldown are counted and suppressed. Expiry does not send anything automatically; a new occurrence is required. |
| `global_limit.max_events` | 100; positive integer, no unlimited mode | Maximum send attempts admitted across all fingerprints in one namespace during each global window. This is not a server-wide limit across namespaces. |
| `global_limit.window_seconds` | 3600 seconds; range 1–31,536,000 | Length of the fixed quota window, aligned to Unix time in UTC. With the default, the shared quota renews at each UTC hour boundary; per-fingerprint cooldowns remain independent. |
| `lock_timeout_ms` | 100 milliseconds; range 1–5000 | Bounds waiting for storage locks. If the deadline is exceeded, the event is suppressed. It does not bound stalled OS syscalls. |
| `retention_seconds` | 604800 seconds (7 days); at least cooldown and global window | Retention horizon used by explicit `cleanup` to remove eligible old records/buckets. Cleanup preserves active cooldowns, current quota and lifetime sums. This setting does not schedule cleanup. |
| `max_fingerprints` | 10000; range 1–100000 | Maximum retained fingerprint records per namespace. At capacity, new fingerprints are suppressed while existing fingerprints continue normally. Cleanup can free capacity. |
| `event_context` | `true` | Adds the local hash and admission-time counters to the admitted event's `sentry_dedupe` context. An existing context with that name is preserved. Set to `false` to omit this enrichment; deduplication still runs. |

### Fingerprint identity

These settings control which occurrences the default strategy considers the same error. They do not change Sentry's server-side grouping. Changing fingerprint settings or strategy requires a new namespace, which starts a fresh budget and deduplication history.

| Setting | Default / constraint | Purpose |
|---|---|---|
| `fingerprint.version` | `fp-v1`; only supported value | Identifies the fingerprint format. Keep the supported value; this is not the application's release version. |
| `fingerprint.project_root` | `null`; otherwise an absolute local path | Normalizes occurrences of the application's root path to `<app>` in identity text. It does not determine which stack frames are marked as application frames; those flags come from the SDK. |
| `fingerprint.deployment_prefixes` | `[]`; map of absolute directories to tokens, e.g. `['/srv/releases/build-1' => '<deploy>']` | Normalizes changing deployment paths to stable tokens. Map equivalent release directories to the same token to prevent path changes alone from splitting an error's identity. |
| `fingerprint.include_release` | `false` | Includes the event's release in the identity when enabled, so different releases get separate fingerprints and cooldowns. They still share the namespace's global quota. |
| `fingerprint.include_line` | `true` | Includes stack frame line numbers in automatic identity. Disabling it avoids splitting an otherwise identical error solely because its line number moved. |
| `fingerprint.max_frames` | 5; range 1–20 | Maximum frames used per selected stack trace, starting nearest the failure. Prefers SDK-marked application frames; falls back to other frames when none are marked. |
| `fingerprint.normalization_rules` | `[]`; ordered `['pattern' => '/order_id=[0-9]+/', 'replacement' => 'order_id=<id>']` pairs | Applies custom PCRE replacements after built-in normalization to identity text eligible for normalization. Use this to remove variable values that should not distinguish errors. Custom Sentry fingerprint components keep their exact contents. |
| `fingerprint.strategy_id` | `default-fp-v1`; custom strategy requires an explicit stable ID | Identifies the fingerprint algorithm in the stored policy. A custom `FingerprintStrategyInterface` implementation is supplied separately to `Factory::create()`; this setting does not load one. Change the ID whenever custom logic changes and keep it consistent across workers. |

Fingerprinting uses prepared exceptions in SDK order, types, messages and application frames nearest the failure. Message parameters are excluded when a template is available. UUIDs, address tokens, whitespace and configured roots are normalized conservatively; meaningful numbers, status codes, SQLSTATE, function names and lines remain distinct. Custom Sentry fingerprint components preserve their exact content and order; `{{ default }}` expands to the local automatic signature. These signatures do not implement Sentry's server grouping algorithm.

Bounds are 16 custom components of 1024 bytes each, 65536 bytes per message, and 262144 bytes of identity input. Invalid UTF-8, PCRE failures and exceeded bounds fail closed. Text is never truncated into a shared key. No hint exception is required; prepared SDK models are the identity source. No original exception arguments or private SDK state are inspected.

Implement `FingerprintStrategyInterface` for alternate rules. Its output must be exactly 64 lowercase hexadecimal SHA-256 characters. Set a new strategy ID whenever its logic changes; keep the ID consistent across workers. No identity payload is stored. Hashes are not encryption or an anonymity guarantee.

## Scope, storage and time

Use a namespace covering application, Sentry project and environment without DSN secrets. Cooperating processes must share the namespace, configuration and local store. This is not a distributed or account-wide quota. A fixed global window can admit twice its maximum across a boundary, while per-key cooldown still crosses that boundary.

Injected Unix-seconds clocks are read under the storage lock. Persisted effective time never decreases, so clock rollback does not reopen an old quota window or shorten cooldown. Forward jumps can expire a period. Event timestamps and local timezones do not drive the decision.

SQLite uses `BEGIN IMMEDIATE`, typed parameters, explicit READWRITE runtime flags, and checked COMMIT. Init alone enables CREATE and creates the schema; a retained `.init.lock` serializes initial file creation/journal negotiation. Each operation closes its own connection; no inherited/pool connection is used. Native SQLite >=3.8.0 is required. Doctor checks the actual engine/settings, integrity and real two-process locking. No PDO API or database connection from the application is used.

JSON stores one namespace snapshot in `sha256(namespace)/state.json`, with permanent `state.lock`. Readers use shared locks; writes use exclusive locks, a private same-directory temporary file, checked writes/fflush, then atomic rename over the snapshot. It uses this same portable process-crash protocol on PHP 7.4 and 8.x, without optional fsync. Windows transient rename failures have at most 20 attempts separated by 5 ms. There is no unlink/copy replacement fallback. Snapshot size and cardinality are bounded. JSON rewrites the entire snapshot; prefer SQLite for higher load. SQLite observation reads only the selected key/current bucket and counts retained keys when needed; statistics/cleanup use a full consistent namespace view.

Directories/files default to 0700/0600 on Unix. Windows needs restricted service ACLs; chmod is not an ACL substitute. Use private paths outside webroot/deployment cleanup. Known symlinks and nonlocal paths are rejected; portable PHP cannot eliminate every filesystem race/reparse-point issue. NFS, SMB and unverified shared volumes are unsupported. Verify a new host/filesystem with doctor and concurrency tests.

Lock deadlines bound waiting, not stalled OS syscalls. Failures are suppressed and do not claim durable counts. JSON provides process-crash atomicity; PHP 7.4 flush/rename does not promise power-loss/kernel-crash durability. SQLite FULL improves durability subject to filesystem/hardware behavior.

## Service user and PHP-FPM verification

Run `init`, `doctor`, `stats` and `cleanup` as the account used by the application's PHP-FPM pool. For a pool running as `www-data:www-data`, use the following Linux examples (replace paths and account names for your deployment):

```sh
sudo install -d -o www-data -g www-data -m 0700 /var/lib/sentry-dedupe/orders
sudo -u www-data /usr/bin/php /srv/orders/vendor/bin/sentry-dedupe init --config=/srv/orders/dedupe.php
sudo -u www-data /usr/bin/php /srv/orders/vendor/bin/sentry-dedupe doctor --config=/srv/orders/dedupe.php --format=json
```

Set `sqlite.path` to `/var/lib/sentry-dedupe/orders/state.sqlite` in this example. Initialize before serving traffic. Running init as root can leave files inaccessible to PHP-FPM: the package uses private file permissions. The service account needs read access to the configuration/autoloader and read/write access to storage, including the directory where SQLite creates its sidecar files. For separate FPM pool accounts, use a separate storage directory/database for each account.

Install cleanup in the service account's crontab with `sudo crontab -u www-data -e`:

```cron
10 * * * * /usr/bin/php /srv/orders/vendor/bin/sentry-dedupe cleanup --config=/srv/orders/dedupe.php --limit=1000
```

The crontab above already runs as `www-data`; its command does not need `sudo`. Preserve the database, sidecars and permanent lockfiles during deployment and log rotation.

### Test CLI and FPM separately

A successful CLI doctor report contains `"healthy": true`. It checks stored state/policy, SQLite integrity/settings and real two-process locking. The locking probe requires `proc_open` and a working CLI PHP binary. Running CLI as the FPM account checks that account's filesystem permissions, but does not exercise the FPM runtime: CLI and FPM can load different PHP versions, `php.ini` files, extensions and access restrictions. Native `sqlite3` must be enabled in both; `pdo_sqlite` alone is insufficient.

To verify actual observation writes and subsequent reads in each runtime, temporarily save the following as `/srv/orders/dedupe-test.php`. It uses the existing, initialized application configuration and never calls Sentry's transport:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Kgkg\SentryDedupe\Factory;
use Kgkg\SentryDedupe\StatsQuery;
use Sentry\Event;

$ok = false;
$result = [
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'php_ini' => php_ini_loaded_file(),
    'sqlite3' => extension_loaded('sqlite3'),
];

try {
    $config = require __DIR__ . '/dedupe.php';
    $event = Event::createEvent()
        ->setMessage('sentry-dedupe storage test')
        ->setFingerprint(['dedupe-storage-test', bin2hex(random_bytes(16))]);

    $first = Factory::create($config)->evaluate($event);
    // A fresh instance must see the first observation's persisted state.
    $second = Factory::create($config)->evaluate($event);
    $stats = Factory::store($config)->stats(StatsQuery::fromArray([
        'fingerprint' => $first->fingerprint(),
    ]))->toArray();
    $record = $stats['fingerprints'][0] ?? [];

    $ok = $first->reason() === 'admit'
        && $second->reason() === 'duplicate'
        && ($record['total_seen'] ?? null) === 2
        && ($record['total_admitted'] ?? null) === 1;
    $result += [
        'first' => $first->reason(),
        'second' => $second->reason(),
        'record' => $record,
    ];
} catch (\Throwable $e) {
    $result['error'] = $e->getMessage();
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($ok ? 200 : 503);
}
echo json_encode(['ok' => $ok] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (PHP_SAPI === 'cli') { exit($ok ? 0 : 1); }
```

Run it through CLI as the service account:

```sh
sudo -u www-data /usr/bin/php /srv/orders/dedupe-test.php
```

Then expose it temporarily through an authenticated or network-restricted HTTP route handled by the application's actual FPM pool. If your public document root is `/srv/orders/public`, place a temporary wrapper there that requires `/srv/orders/dedupe-test.php`; keep the configuration and storage outside the public directory. Request that protected route through your web server. Do not accept a configuration path from request parameters.

Both runs should return `"ok": true`, `"first": "admit"`, `"second": "duplicate"`, and a record with `total_seen: 2` and `total_admitted: 1`. For FPM, `sapi` should be `fpm-fcgi`. The HTTP status is 200 on success or 503 on failure; CLI returns 0 or 1 respectively.

Each invocation creates a new fingerprint and, on success, consumes one admission in the configured namespace even though nothing is sent to Sentry. An exhausted quota (`global_limit`) or fingerprint capacity (`capacity`) also fails the test without implying broken storage. Use a separately initialized test namespace in the same database if production counters/budget must remain untouched; that verifies storage access, while CLI doctor with the production config verifies its stored policy/state. Remove the script, wrapper and temporary route after testing. Do not use this write test as a recurring public health endpoint: it changes state and reveals runtime details. Do not evaluate a real event here and then pass that same occurrence through `before_send` again.

## Operations and recovery

```sh
php vendor/bin/sentry-dedupe stats --config=dedupe.php --format=json --limit=20 --sort=total_seen
php vendor/bin/sentry-dedupe stats --config=dedupe.php --fingerprint=HASH
php vendor/bin/sentry-dedupe stats --config=dedupe.php --from=1792454400 --to=1792540800
php vendor/bin/sentry-dedupe cleanup --config=dedupe.php --dry-run --limit=1000
php vendor/bin/sentry-dedupe cleanup --config=dedupe.php --limit=1000
```

Schedule cleanup hourly using your deployment scheduler, under the same service account and config. Cleanup never runs randomly in requests and never VACUUMs/checkpoints automatically. It preserves active cooldowns, current quota, policy and lifetime sums. Without cleanup, reaching cardinality capacity denies new keys while existing keys continue normally. JSON cleanup removes only library-named temporary files older than 24 hours under the stable lock; the lockfile remains.

Statistics distinguish lifetime totals, retained fingerprints and bucket-range totals. Range bounds are half-open Unix timestamps aligned with the configured window. Earlier-than-watermark, current and future ranges report incompleteness. Initialization inside a partial bucket is disclosed. Stats/dry-run do not write or advance persisted time. Deleted/recreated fingerprints start fresh record counts while lifetime sums remain.

Exit codes: 0 success; 1 storage/execution/unhealthy; 2 arguments/configuration; 3 schema/policy mismatch. JSON goes to stdout, errors to stderr. Help/version need no config. Config files execute trusted PHP; never expose arbitrary config paths over HTTP.

For a supported policy change, stop old workers, prepare the new config, obtain the old `policy_hash` from stats, then run:

```sh
php vendor/bin/sentry-dedupe policy:apply --config=new-dedupe.php --expect-policy-hash=OLD_HASH
```

Start new workers after apply. Admission times, lifetime sums and current usage are preserved, including when reducing the maximum below used quota. Old workers after apply/new workers before apply fail closed. Changing the window length or fingerprint settings/strategy requires a **new namespace** and explicit acknowledgment of a fresh budget. Changing backend is not a migration/fallback: stop traffic, initialize a separate store/namespace, acknowledge history reset, then switch workers.

For corruption or lost files, stop traffic/workers first, inspect permissions/disk/doctor output and preserve evidence. There is no automatic recovery from temporary files or old backups. Deliberate repair/restoration/reset can admit an error again and may reset quota history. JSON interrupted initialization with a marked lock and missing snapshot requires deliberate operator recovery; init will not silently reset it. Never delete live SQLite journal/WAL/SHM sidecars. A backup restored to an earlier point loses deduplication guarantees for the lost history.

Diagnostics are disabled by default. An optional independent callable receives only safe reason/backend metadata, at most once per reason per 60 monotonic seconds per instance. It must not use Sentry, the application's database, or a Sentry-backed logging handler. Its exceptions and recursive invocations are contained; diagnostics cannot replace durable counts during storage failure.

### Separate diagnostic log

To record dedupe's own failures independently, prepare a private log directory/file writable by the same service account. For example:

```sh
sudo install -d -o www-data -g www-data -m 0700 /var/log/sentry-dedupe
sudo -u www-data touch /var/log/sentry-dedupe/orders.log
sudo chmod 0600 /var/log/sentry-dedupe/orders.log
```

Pass the diagnostic callback as the fourth argument to `Factory::create()` in the application bootstrap. It is not a `Config::fromArray()` setting:

```php
$config = require __DIR__ . '/dedupe.php';
$dedupe = \Kgkg\SentryDedupe\Factory::create(
    $config,
    null,
    null,
    static function (string $reason, array $metadata): void {
        // Diagnostics also receive normal decisions such as admit/duplicate.
        // Keep this file focused on failures.
        if (substr($reason, -6) !== '_error') { return; }

        error_log(
            gmdate('c') . ' reason=' . $reason
            . ' backend=' . $metadata['backend'] . PHP_EOL,
            3,
            '/var/log/sentry-dedupe/orders.log'
        );
    }
);

// Use this instance in Sentry's before_send callback as shown above.
```

`error_log` message type 3 appends directly to the named file rather than using the configured PHP error log. Use a different filename per application when they need separate diagnostic logs, and keep logs outside the webroot. Configure rotation to recreate the file with the service account's ownership and private permissions; rotate the diagnostic log only, never the SQLite database or its sidecars.

This example logs `storage_error` (including missing `sqlite3`, inaccessible storage, lock failures or policy mismatch), `fingerprint_error` and `callback_error`. The callback receives a generic reason/backend, not the underlying exception text or event payload. Use CLI `doctor` as the service account and the FPM test above to investigate storage failures. With these failures, `before_send` drops the event; there is no automatic retry or fallback backend.

The 60-second suppression is per reason **per dedupe instance**, not per server or FPM pool. Separate requests that construct new instances can therefore produce separate log entries. Diagnostic logging is best effort: an unwritable log or callback failure does not reopen admission or guarantee another warning. Verify the diagnostic file's permissions separately from the database.

## Development and evidence

```sh
composer validate --strict
composer install
composer check-platform-reqs
composer lint
composer test
composer test:contract
composer test:concurrency
composer analyse
composer benchmark
```

The concurrency runner uses actual 32-process barriers: ten 500-observation repeated-key runs per backend, distinct-key quota, concurrent stats/cleanup/observe, and concurrent init. Any storage failure invalidates a correctness run. Benchmark separately reports failures and p50/p95/p99 for 1/8/32 workers at 1000 initial fingerprints; its 5000 ms timeout is a test setting, not the production default. Optional `SENTRY_DEDUPE_BACKEND=json|sqlite` selects one backend. Temporary test data/evidence are under `.cache/`; production never defaults to that directory.

See [acceptance report](docs/ACCEPTANCE.md) and CI for exact tested runtimes, dependencies, filesystem, limitations and unexecuted checks. PHPStan 2.2 and PHPUnit 9.6 retain the PHP 7.4 developer baseline. CI retains resolved lockfiles and concurrency evidence for each matrix combination.
