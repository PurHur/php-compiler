#!/usr/bin/env bash
# Run one app under Zend / VM / AOT and print machine-readable RESULT lines (#36380).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
SLUG="$(basename "$APP_DIR")"
PHP_BIN="${PHP_BIN:-php}"
VM_TIMEOUT="${APPS_VM_TIMEOUT:-90}"
AOT_TIMEOUT="${APPS_AOT_TIMEOUT:-300}"
AOT_MEMORY="${APPS_AOT_MEMORY:-2048M}"

cd "$ROOT"
source script/php-env.sh 2>/dev/null || true

parse_summary() {
  local out="$1"
  local pass fail skip
  pass=$(printf '%s\n' "$out" | sed -n 's/.*pass=\([0-9][0-9]*\).*/\1/p' | tail -1)
  fail=$(printf '%s\n' "$out" | sed -n 's/.*fail=\([0-9][0-9]*\).*/\1/p' | tail -1)
  skip=$(printf '%s\n' "$out" | sed -n 's/.*skip=\([0-9][0-9]*\).*/\1/p' | tail -1)
  echo "${pass:-} ${fail:-} ${skip:-}"
}

short_reason() {
  printf '%s' "$1" | tr '\n' ' ' | sed 's/  */ /g' | sed 's/^ *//' | cut -c1-200
}

emit_result() {
  local backend="$1" status="$2" pass="$3" fail="$4" skip="$5" rc="$6" reason="${7:-}"
  if [[ -n "$reason" ]]; then
    reason="${reason// /_}"
    echo "RESULT slug=$SLUG backend=$backend status=$status pass=$pass fail=$fail skip=$skip rc=$rc reason=$reason"
  else
    echo "RESULT slug=$SLUG backend=$backend status=$status pass=$pass fail=$fail skip=$skip rc=$rc"
  fi
}

run_backend() {
  local backend="$1"
  local out="" rc=0 pass="" fail="" skip="" reason=""
  case "$backend" in
    zend)
      set +e
      out="$($PHP_BIN "$APP_DIR/runner.php" 2>&1)"
      rc=$?
      set -e
      ;;
    vm)
      set +e
      out="$(timeout "$VM_TIMEOUT" $PHP_BIN bin/vm.php "$APP_DIR/runner.php" 2>&1)"
      rc=$?
      set -e
      if [[ "$rc" -eq 124 ]]; then
        reason="vm_timeout_${VM_TIMEOUT}s"
      fi
      ;;
    aot)
      local bin="$APP_DIR/.phpc/bin/parser-runner"
      mkdir -p "$APP_DIR/.phpc/bin"
      set +e
      out="$(timeout "$AOT_TIMEOUT" $PHP_BIN -d "memory_limit=${AOT_MEMORY}" bin/compile.php -o "$bin" "$APP_DIR/runner.php" 2>&1)"
      local build_rc=$?
      set -e
      if [[ "$build_rc" -ne 0 ]] || [[ ! -x "$bin" ]]; then
        reason="$(short_reason "$out")"
        if echo "$out" | grep -q 'Allowed memory size'; then
          reason="aot_compile_oom_${AOT_MEMORY}_#36387"
        elif [[ "$build_rc" -eq 124 ]]; then
          reason="aot_compile_timeout_${AOT_TIMEOUT}s_#36387"
        elif [[ "$build_rc" -eq 137 ]]; then
          reason="aot_compile_sigkill_cgroup_oom_#36387"
        fi
        emit_result aot block 0 0 0 "$build_rc" "$reason"
        return 0
      fi
      set +e
      out="$(timeout "$VM_TIMEOUT" "$bin" 2>&1)"
      rc=$?
      set -e
      if [[ "$rc" -eq 124 ]]; then
        reason="aot_run_timeout_${VM_TIMEOUT}s"
      fi
      ;;
    *)
      echo "unknown backend: $backend" >&2
      return 2
      ;;
  esac

  read -r pass fail skip <<<"$(parse_summary "$out")"
  if [[ -z "$pass" || -z "$fail" ]]; then
    if [[ -z "$reason" ]]; then
      if [[ "$rc" -eq 124 ]]; then
        reason="${backend}_timeout_no_summary"
      else
        reason="$(short_reason "$out")"
        [[ -z "$reason" ]] && reason="${backend}_no_summary_rc_${rc}"
      fi
    fi
    emit_result "$backend" block 0 0 0 "$rc" "$reason"
    return 0
  fi

  local status=ok
  if [[ -n "$reason" ]]; then
    status=block
  elif [[ "$rc" -ne 0 || "$fail" -gt 0 ]]; then
    status=fail
  fi
  emit_result "$backend" "$status" "$pass" "$fail" "$skip" "$rc" "$reason"
}

for b in zend vm aot; do
  run_backend "$b"
done
