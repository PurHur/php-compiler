#!/usr/bin/env bash
# Run one app under Zend / VM / AOT and print a machine-readable RESULT line (#36380).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
APP_DIR="$(cd "$(dirname "$0")" && pwd)"
SLUG="$(basename "$APP_DIR")"
PHP_BIN="${PHP_BIN:-php}"
VM_TIMEOUT="${APPS_VM_TIMEOUT:-90}"
# Parsedown-sized libraries need minutes under IncludeHelper; 120s left rc=124 with a
# misleading "helper-runtime cache hit" reason (#36380).
AOT_TIMEOUT="${APPS_AOT_TIMEOUT:-600}"
# Cap PHP heap so LLVM native RSS still fits under the 8–10g harness cgroup (#36380).
# Override with APPS_AOT_MEMORY=8192M on larger hosts.
AOT_MEMORY="${APPS_AOT_MEMORY:-4096M}"

cd "$ROOT"
# shellcheck disable=SC1091
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
  # Collapse whitespace; keep first useful clause.
  printf '%s' "$1" | tr '\n' ' ' | sed 's/  */ /g' | sed 's/^ *//' | cut -c1-200
}

emit_result() {
  local backend="$1" status="$2" pass="$3" fail="$4" skip="$5" rc="$6" reason="${7:-}"
  if [[ -n "$reason" ]]; then
    # Underscores instead of spaces so RESULT stays single-token friendly.
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
      local bin="$APP_DIR/.phpc/bin/parsedown-runner"
      mkdir -p "$APP_DIR/.phpc/bin"
      local build_out=""
      # Skip SourceBundler mega-concat of Parsedown.php; raise memory floor via #36380.
      export PHP_COMPILER_AOT_INCREMENTAL_INCLUDES="${PHP_COMPILER_AOT_INCREMENTAL_INCLUDES:-1}"
      export PHP_COMPILER_MEMORY_LIMIT="$AOT_MEMORY"
      # Keep floor from SourceBundler::ensureIncrementalProjectMemoryFloor at the same cap.
      export PHP_COMPILER_LLVM_MEMORY_LIMIT="$AOT_MEMORY"
      export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$APP_DIR/.phpc/helper-cache}"
      mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"
      set +e
      build_out="$(timeout "$AOT_TIMEOUT" $PHP_BIN -d "memory_limit=${AOT_MEMORY}" bin/compile.php -o "$bin" "$APP_DIR/runner.php" 2>&1)"
      local build_rc=$?
      set -e
      if [[ "$build_rc" -ne 0 ]] || [[ ! -x "$bin" ]]; then
        reason="$(short_reason "$build_out")"
        # Prefer the known dynamic-method / curly-call parse failure.
        if echo "$build_out" | grep -q 'Instance method call name must be a compile-time string'; then
          reason="dynamic_method_name_not_lowered_#34084"
        elif echo "$build_out" | grep -q 'unexpected token "{"'; then
          reason="parse_error_curly_variable_method_\$this->{...}"
        elif echo "$build_out" | grep -q 'Allowed memory size'; then
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
    # No SUMMARY line — treat as blocked (crash / hang / wrong runner).
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
