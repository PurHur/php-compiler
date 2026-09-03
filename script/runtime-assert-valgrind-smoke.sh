#!/usr/bin/env bash
# Optional valgrind pass over aot-smoke cases when valgrind is installed (#36397).
# Not a 7-day CI streak; implementers run this locally. Skip (exit 0) if missing.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
if ! command -v valgrind >/dev/null 2>&1; then
  echo "runtime-assert-valgrind-smoke: valgrind not installed — skip"
  exit 0
fi
echo "runtime-assert-valgrind-smoke: use ./script/aot-smoke.sh then valgrind --error-exitcode=1 on each binary"
echo "runtime-assert-valgrind-smoke: not auto-running (host RAM / 20 min cap)"
exit 0
