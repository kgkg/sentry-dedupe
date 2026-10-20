#!/bin/sh
set -eu
input=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
work=$(mktemp -d /tmp/sentry-dedupe-native.XXXXXXXX)
tar -xf "$input/.local/source.tar" -C "$work"
printf '%s\n' "$work" > "$input/.local/linux/native-workdir.txt"
status=0
sh "$work/tools/verify-linux.sh" || status=$?
cp "$work"/docs/evidence/linux83-* "$input/docs/evidence/"
exit "$status"
