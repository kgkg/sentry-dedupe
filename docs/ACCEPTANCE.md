# Implementation and acceptance report

Date: October 20, 2026. Specification: [SPEC.md](SPEC.md), revision 1.1, with the user's `kgkg` namespace override. Package: `kgkg/sentry-dedupe`; PHP namespace: `Kgkg\SentryDedupe`.

The implementation, CLI, examples, contract tests, process tests and CI configuration are delivered. Local evidence covers Windows and Linux, normal and SDK 4.0.0 dependency sets, actual file locks, native SQLite transactions, process termination and extension isolation. This is not a claim that the entire remote CI matrix has run or that every hardware failure has been reproduced.

## Reproduction and environment

`tools/verify.php` writes local check output to `docs/evidence/`. `tools/verify-linux.sh` verifies native JSON/SQLite without PDO on the available Ubuntu PHP 8.3 installation, with isolated modules extracted into `.local/linux/`; it does not install modules into the host. `tools/verify-linux-native.sh` extracts the test archive to a unique `/tmp/sentry-dedupe-native.*` directory on native WSL ext4 and returns evidence to this workspace. Those local path/runtime helpers complement the portable Composer commands and CI.

Reference Windows machine: Intel Core Ultra 7 155H, 16 cores / 22 logical processors, 33,630,175,232 bytes physical memory, Windows NT build 26300 x64, local NTFS C: volume, NVMe SSD storage (Samsung MZAL8512HDLU / Samsung SSD 980). PHP 7.4.33 uses native SQLite 3.31.1. This is a developer workstation; timings are observations, not service SLAs.

Local runtimes exercised: Windows PHP 7.4.33, 8.1.31 and 8.5.11; Linux PHP 7.4.33 in a disposable existing image and Ubuntu/WSL PHP 8.3.6 with SQLite 3.45.1. The Docker check extracts sources/dependencies to a fresh container-local `/work` before testing; final WSL isolation uses native ext4. An earlier mounted-Windows WSL SQLite stress run exceeded its lock deadline and does not qualify that shared filesystem. Process locking/atomic replacement on the final local test filesystems is verified by doctor and concurrency tests. This does not certify NFS/SMB or every Windows filesystem/ACL combination.

The normal root `composer.lock` resolves Sentry 4.34.0, PHPUnit 9.6.38 and PHPStan 1.12.34. [php74-sdk400.lock](evidence/php74-sdk400.lock) retains the exact lowest resolution used with Sentry 4.0.0, PHPUnit 9.6.33 and PHPStan 1.12.0. No platform requirements were ignored. Baseline SDK getter/transport signatures were checked against the installed sources and executed by the SDK integration suite.

The final full suite has **73 tests**; exact assertion totals are retained in each run's log. It also checks that external PHP references cannot mutate validated configuration. The JSON-only Linux suite has 65 cases with two explicit native-SQLite skips; the SQLite isolation suite executes all 65 applicable cases. Linux PHP 7.4's native engine is SQLite 3.34.1. Separate Windows 7.4/8.5 and Linux 7.4 reports each cover both backends; Linux 8.3 reports split JSON-only and SQLite-only extension configurations.

## Evidence

| Area | Artifact / assertions |
|---|---|
| PHP 7.4 parsing | [lint-74.txt](evidence/lint-74.txt); production, CLI, examples and baseline tests |
| Baseline tests | [unit-74.txt](evidence/unit-74.txt), [contract-74.txt](evidence/contract-74.txt) |
| PHP 8.x | [unit-81.txt](evidence/unit-81.txt), [unit-85.txt](evidence/unit-85.txt) |
| SDK 4.0.0 | [unit-74-sdk400.txt](evidence/unit-74-sdk400.txt), exact lockfile above |
| Static analysis | [analysis-74.txt](evidence/analysis-74.txt), PHPStan level 6 targeting PHP 7.4 |
| Windows concurrency | [concurrency-74.txt](evidence/concurrency-74.txt), [concurrency-85.txt](evidence/concurrency-85.txt), matching JSON reports |
| Linux baseline | [linux74-unit.txt](evidence/linux74-unit.txt), [linux74-concurrency.txt](evidence/linux74-concurrency.txt) |
| No database extensions | [linux83-isolation-json.txt](evidence/linux83-isolation-json.txt), [linux83-unit-json.txt](evidence/linux83-unit-json.txt), [linux83-concurrency-json.txt](evidence/linux83-concurrency-json.txt) |
| Native SQLite without PDO | [linux83-isolation-sqlite.txt](evidence/linux83-isolation-sqlite.txt), [linux83-unit-sqlite.txt](evidence/linux83-unit-sqlite.txt), [linux83-concurrency-sqlite.txt](evidence/linux83-concurrency-sqlite.txt) |
| Performance | [benchmark-7.4.33.json](evidence/benchmark-7.4.33.json), [benchmark-74.txt](evidence/benchmark-74.txt) |

Isolation output lists the actual loaded extensions. The JSON job has no SQLite3, PDO or PDO_SQLite. The native SQLite job has SQLite3 and no PDO/PDO_SQLite. Missing SQLite selected deliberately fails closed with `missing_ext_sqlite3`; JSON still operates. SQLite-specific tests are explicitly skipped only in the JSON-only isolation job, and execute in the SQLite/full jobs. Concurrency correctness runs never skip storage failures or count them as suppressions.

Each full concurrency report has 26 runs: ten repeated-key runs per backend, then distinct-key quota, mixed cleanup/stats/observe, and initialization per backend. Each repeated run uses a real start barrier and 32 processes to commit exactly **500 observations, 1 admission and 499 duplicate suppressions**. The distinct-key run commits exactly **10 admissions and 490 global-limit denials**. Mixed tests preserve those totals; concurrent init yields 32 successful idempotent initializations.

JSON process-kill tests stop the actual store before write, after write, after flush, before rename and after rename. Old/new snapshots remain whole, and the reservation survives a kill after rename. SQLite native-engine kill tests use the library schema and the same transaction invariant before/after COMMIT; an additional actual-library COMMIT contention test forces rollback with a real reader lock. These are process-termination proofs, not power-loss proofs.

Fault tests cover false/zero/short writes, flush and rename failure, malformed/missing JSON and lockfiles, invalid map/count types, overflow, cardinality and size caps, real lock deadlines, native SQLite busy commits, callback failures/reentrancy and failed SDK transport. A failed operation must not overwrite the committed snapshot or refund a reservation.

## Performance interpretation

The benchmark preloads 1000 fingerprints and reports 512 timed observations for repeated/new-key workloads at 1, 8 and 32 workers. The JSON artifact includes CPU milliseconds, peak memory per worker, wall time, p50/p95/p99, PHP/SDK/SQLite versions, CPU and OS. Reported memory is the maximum per worker, not host-wide resident memory. Storage failures, timeout counts and failure categories remain in the report; they are not hidden from percentiles.

JSON rewrites/validates its whole bounded snapshot. SQLite uses indexed selected-key/current-bucket reads and updates under `BEGIN IMMEDIATE`, counting retained keys for new fingerprints. SQLite retains mandatory FULL synchronization; both retain fail-closed admission and exclusive write locks. JSON uses the portable checked-fflush/atomic-rename protocol on every runtime and omits the specification's optional fsync. This keeps its PHP 7.4 process-crash guarantee consistent on PHP 8.x; it does not offer power-loss durability. On this Windows reference, JSON's single-process p95 is below the indicative 50 ms target. SQLite's single-process p95 can exceed the indicative 10 ms target because connections, WAL lifecycle and FULL synchronization are included.

At 32 workers workloads may exceed the benchmark's 5000 ms deadline; those denials are explicitly recorded for either backend. This stress benchmark with 1000 initial records differs from the 500-observation correctness test. It is not evidence that all workloads meet the production default 100 ms wait budget. Use the measured workload, SQLite for higher load, appropriate cardinality and explicit deployment testing when sizing the service.

## Acceptance mapping

| Criterion | Evidence and practical boundary |
|---|---|
| AC-01 | Actual 7.4/8.1/8.3/8.5 execution and 7.4 lint; complete 7.4–8.5 remote matrix is configured, not yet executed here |
| AC-02 | CLI initialization/examples and `CallbackTest` / real `SdkTest` require no capture-call changes |
| AC-03–04 | Shared 500-event contract test, exact +3599/+3600 boundary and pending counters; process proofs above |
| AC-05 | Shared close/reopen/idempotent-init contract tests and independent process runs |
| AC-06 | Actual 32-worker repeated/quota/mixed/init runs on both backends, with no allowed storage errors in correctness assertions |
| AC-07 | Quota, namespace isolation, fixed-boundary behavior, capacity/duplicate priority and policy-reduction tests |
| AC-08 | Native SQLite3 and JSON extension-isolation logs; no network/application DB decision path |
| AC-09 | Fault/timeout/callback/diagnostic tests deny safely; failed durable counting is not represented as successful zero-valued statistics |
| AC-10 | Custom/default-token identity, UUID/address/path rules, templates, frames, types, chains, meaningful numbers, UTF-8 and PCRE tests |
| AC-11 | Shared atomic policy, JSON crash checkpoints, native SQLite atomicity and library COMMIT contention; hardware-power-loss qualification remains outside local evidence |
| AC-12 | Shared cleanup, lifetime/retained/range completeness, dry-run and concurrent-view tests |
| AC-13 | Bounded cardinality and JSON pre-read/pre-commit size enforcement, active cooldown protection and unchanged-state failure assertions |
| AC-14 | Previous callback composition, null hints, replacement Event, real SDK captures and failed fake transport retaining reservation |
| AC-15 | Only hashes/counters/policy metadata persist; `PrivacyTest` checks that event message/extra secrets do not enter state/diagnostics |
| AC-16 | README, examples, CLI help/tests, explicit init/recovery/policy instructions and this evidence report |

## Remaining qualification and declared limits

- The GitHub Actions Linux matrix for PHP 7.4, 8.0–8.5, normal/lowest dependencies, Windows 7.4/8.5 and extension isolation is supplied. It has not been submitted to GitHub from this workspace (which is not a Git checkout). Its future results must not be described as already passing. CI uploads actual resolved lockfiles and per-runtime evidence.
- Local no-PDO isolation is on PHP 8.3; PHP 7.4 native tests here include PDO in the interpreter, without using it. CI adds the PHP 7.4 no-PDO jobs. The oldest locally exercised native SQLite engine is 3.31.1, not the declared 3.8.0 minimum; an older-engine qualification remains outstanding.
- Not every native SQLite prepare/bind/finalize/COMMIT syscall failure has an injectable test. Disk exhaustion on a constrained volume, kernel crashes, real power failure and every filesystem/reparse-point race were not reproduced. File-operation failure seams cover the JSON failure protocol; native locking/transaction failures use the actual engine.
- JSON on PHP 7.4 has no portable power-loss/directory-sync guarantee. A corrupt/lost/reset/restored-old store can permit repeat admission after deliberate repair. No distributed quota, NFS/SMB guarantee, automatic recovery, backend fallback, event replay or delivery acknowledgment is provided.
- Publication identity is now `kgkg/sentry-dedupe`. The owner still needs to select copyright/license terms before publishing; the current proprietary manifest does not grant redistribution rights.
