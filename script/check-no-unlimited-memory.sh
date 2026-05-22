#!/usr/bin/env bash
# Fail if the repo or current env enables unlimited PHP memory (memory_limit=-1).
# Issue #497 follow-up: unbounded heap allowed vm.php to reach 40+ GiB RSS.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

fail=0

for var in PHP_COMPILER_MEMORY_LIMIT PHP_COMPILER_LLVM_MEMORY_LIMIT; do
  val="${!var:-}"
  if [[ "$val" == "-1" ]]; then
    echo "check-no-unlimited-memory: ${var}=-1 is not allowed (use e.g. 1536M or 4G)." >&2
    fail=1
  fi
done

FIRST_PARTY_DIRS=(lib bin src script test Docker patches ext benchmarks examples templates php)

scan_dirs() {
  local pattern="$1"
  shift
  local dir line
  for dir in "${FIRST_PARTY_DIRS[@]}"; do
    [[ -d "$dir" ]] || continue
    grep -RInE "$pattern" "$dir" "$@" 2>/dev/null || true
  done
}

while IFS= read -r hit; do
  [[ -z "$hit" ]] && continue
  echo "check-no-unlimited-memory: forbidden ini directive: $hit" >&2
  fail=1
done < <(scan_dirs '^memory_limit=-1' --include='*.ini')

while IFS= read -r hit; do
  [[ -z "$hit" ]] && continue
  echo "check-no-unlimited-memory: forbidden ini_set: $hit" >&2
  fail=1
done < <(scan_dirs "ini_set\\s*\\(\\s*['\\\"]memory_limit['\\\"]\\s*,\\s*['\\\"]-1['\\\"]" --include='*.php')

while IFS= read -r hit; do
  [[ -z "$hit" ]] && continue
  if [[ "$hit" == *check-no-unlimited-memory.sh* ]]; then
    continue
  fi
  echo "check-no-unlimited-memory: forbidden -d memory_limit=-1: $hit" >&2
  fail=1
done < <(scan_dirs '(-d[[:space:]]+memory_limit=-1|memory_limit=-1)' --include='*.sh' | grep -vE '^[^:]*:[0-9]+:#' || true)

if [[ "$fail" -ne 0 ]]; then
  exit 1
fi

echo "check-no-unlimited-memory: OK (no memory_limit=-1 in tree or environment)."
