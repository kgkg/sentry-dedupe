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

| Setting | Default / constraint |
|---|---|
| namespace | Required, 1–200 bytes, UTF-8 without control characters |
| backend | Required: `sqlite` or `json` |
| sqlite.path / json.directory | Required for the selected backend; absolute local path without dot segments/stream wrappers |
| sqlite.journal_mode | `WAL`; alternatively `DELETE` |
| sqlite.synchronous | `FULL`, required |
| json.max_state_bytes | 16 MiB; range 1–256 MiB |
| interval_seconds | 3600; range 1–31,536,000 |
| global_limit.max_events | 100; positive integer, no unlimited mode |
| global_limit.window_seconds | 3600; range 1–31,536,000 |
| lock_timeout_ms | 100; range 1–5000 |
| retention_seconds | 604800; at least cooldown and global window |
| max_fingerprints | 10000; range 1–100000 |
| event_context | true |
| fingerprint.version | `fp-v1` |
| fingerprint.project_root | null; set the application's absolute root explicitly |
| fingerprint.deployment_prefixes | map of absolute directories to tokens, e.g. `['/srv/releases/build-1' => '<deploy>']` |
| fingerprint.include_release / include_line | false / true |
| fingerprint.max_frames | 5; range 1–20 |
| fingerprint.normalization_rules | Ordered `['pattern' => '/order_id=[0-9]+/', 'replacement' => 'order_id=<id>']` pairs |
| fingerprint.strategy_id | `default-fp-v1`; custom strategy requires an explicit stable ID |

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
