#!/usr/bin/env bash
# Profile peak RSS per compliance PHPT via bin/vm.php (issue #500). For local leak hunts only.
#
# Usage: ./script/scan-vm-phpt-peak-rss.sh [cases-dir] [limit]
# Default: test/compliance/cases 50 files
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck source=php-env.sh
source "$REPO_ROOT/script/php-env.sh"
# shellcheck source=ci-defaults.env
source "$REPO_ROOT/script/ci-defaults.env"

CASES_DIR="${1:-$REPO_ROOT/test/compliance/cases}"
LIMIT="${2:-50}"
VM="$REPO_ROOT/bin/vm.php"
GUARD="$REPO_ROOT/script/run-vm-guarded.sh"

if [[ ! -x "$GUARD" ]]; then
  echo "Missing $GUARD" >&2
  exit 1
fi

echo "scan-vm-phpt: dir=${CASES_DIR} limit=${LIMIT} memory_limit=${PHP_COMPILER_MEMORY_LIMIT}"
count=0
while IFS= read -r phpt; do
  [[ -f "$phpt" ]] || continue
  count=$((count + 1))
  [[ "$count" -le "$LIMIT" ]] || break

  file_section=$(awk '/^--FILE--$/{flag=1;next}/^--[A-Z]+--$/{if(flag) exit}flag' "$phpt")
  log=$(
    printf '%s' "$file_section" \
      | PHP_COMPILER_VM_PEAK_RSS_MB=8192 "$GUARD" "$PHP_BIN" "${PHP_OPTS[@]}" "$VM" 2>&1 \
      || true
  )
  peak_mb=$(echo "$log" | sed -n 's/.*peak_rss_mb=\([0-9][0-9]*\).*/\1/p' | tail -1)
  peak_mb=${peak_mb:-?}
  printf '%5s MiB  %s\n' "$peak_mb" "${phpt#$REPO_ROOT/}"
done < <(find "$CASES_DIR" -name '*.phpt' | sort)
echo "scan-vm-phpt: scanned ${count} file(s)"
