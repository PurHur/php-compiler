#!/usr/bin/env bash
# CLI helper: run a command with peak-RSS watchdog (issue #500).
# PHPUnit PHPT tests use BaseTest::runVmSubprocess() instead (stdin-safe).
# Usage: run-vm-guarded.sh COMMAND [ARGS...]
# Example: printf '%s' '<?php echo 1;' | run-vm-guarded.sh php bin/vm.php
set -euo pipefail

MAX_MB="${PHP_COMPILER_VM_PEAK_RSS_MB:-2048}"
MAX_KB=$((MAX_MB * 1024))

if [[ $# -lt 1 ]]; then
  echo "run-vm-guarded: missing command" >&2
  exit 2
fi

collect_tree_pids() {
  local root="$1"
  local kids
  printf '%s\n' "$root"
  kids="$(pgrep -P "$root" 2>/dev/null || true)"
  for c in $kids; do
    collect_tree_pids "$c"
  done
}

peak_kb=0
"$@" &
root_pid=$!

while kill -0 "$root_pid" 2>/dev/null; do
  while read -r pid; do
    [[ -z "$pid" ]] && continue
    if [[ -r "/proc/${pid}/status" ]]; then
      rss_kb=$(awk '/^VmRSS:/ {print $2}' "/proc/${pid}/status" 2>/dev/null || echo 0)
      if [[ "$rss_kb" -gt "$peak_kb" ]]; then
        peak_kb=$rss_kb
      fi
    fi
  done < <(collect_tree_pids "$root_pid")
  if [[ "$peak_kb" -gt "$MAX_KB" ]]; then
    kill -TERM "$root_pid" 2>/dev/null || true
    sleep 0.5
    kill -KILL "$root_pid" 2>/dev/null || true
    wait "$root_pid" 2>/dev/null || true
    echo "run-vm-guarded: peak RSS $((peak_kb / 1024)) MiB exceeds cap ${MAX_MB} MiB (pid tree rooted at ${root_pid})" >&2
    exit 137
  fi
  sleep 0.15
done

set +e
wait "$root_pid"
exit_code=$?
set -e
echo "run-vm-guarded: peak_rss_mb=$((peak_kb / 1024)) exit=${exit_code}" >&2
exit "$exit_code"
