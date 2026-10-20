#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root"
mkdir -p .local/linux docs/evidence
export PHP_INI_SCAN_DIR="$root/.local/linux/no-ini"
system_dir=$(php -n -r 'echo ini_get("extension_dir");')
module_dir="$root/.local/linux/modules/usr/lib/php/20230831"
for backend in json sqlite; do
    ini="$root/.local/linux/php83-$backend.ini"
    : > "$ini"
    printf 'memory_limit=512M\n' >> "$ini"
    for ext in curl mbstring dom xml xmlwriter xmlreader simplexml tokenizer; do
        if [ -f "$system_dir/$ext.so" ]; then
            printf 'extension=%s/%s.so\n' "$system_dir" "$ext" >> "$ini"
        elif [ -f "$module_dir/$ext.so" ]; then
            printf 'extension=%s/%s.so\n' "$module_dir" "$ext" >> "$ini"
        fi
    done
    if [ "$backend" = sqlite ]; then
        printf 'extension=%s/sqlite3.so\n' "$module_dir" >> "$ini"
    fi
    export SENTRY_DEDUPE_BACKEND="$backend"
    php -c "$ini" tests/isolation.php > "docs/evidence/linux83-isolation-$backend.txt" 2>&1
    php -c "$ini" vendor/phpunit/phpunit/phpunit > "docs/evidence/linux83-unit-$backend.txt" 2>&1
    php -c "$ini" tests/Concurrency/run.php > "docs/evidence/linux83-concurrency-$backend.txt" 2>&1
    cp ".cache/concurrency-8.3.6-Linux.json" "docs/evidence/linux83-concurrency-$backend.json"
    printf '%s isolation, unit, concurrency passed\n' "$backend"
done
