#!/usr/bin/env bash
# Honest compile metric for release-readiness JSON (#15603).
#
# Reports whether inventory argv / gen-2 compile paths avoid gen-0 sidecar recovery.
# Informational only — does not fail release-readiness.
#
# Usage:
#   ./script/bootstrap-honest-compile-metric.sh [--check] [--json]
#
# --check   Fast wiring probe only (no LLVM link).
# --json    Machine JSON on stdout; human log on stderr.
#
# Exit codes:
#   0  metric collected
#   1  missing scripts
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CHECK_ONLY=0
JSON_OUT=0

usage() {
  cat <<EOF
Usage: script/bootstrap-honest-compile-metric.sh [--check] [--json]

Honest compile metric (#15603) for release-readiness.sh --json.

  --check   Fast wiring only (no inventory argv link)
  --json    Emit {"status","message","gate_available"} on stdout

Status values:
  yes      sidecar-free inventory argv link succeeded
  no       sidecar recovery / emit-helper dependency still present
  skip     LLVM 9 unavailable
  unknown  --check mode or probe not run
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --check) CHECK_ONLY=1 ;;
    --json) JSON_OUT=1 ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "bootstrap-honest-compile-metric: unknown argument: ${arg}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-honest-compile-lib.sh
source "$(dirname "$0")/bootstrap-honest-compile-lib.sh"

metric_log() {
  if [[ "${JSON_OUT}" -eq 1 ]]; then
    echo "bootstrap-honest-compile-metric: $*" >&2
  else
    echo "bootstrap-honest-compile-metric: $*"
  fi
}

metric_emit() {
  local status="$1"
  local message="$2"
  local gate_available="${3:-true}"
  if [[ "${JSON_OUT}" -eq 1 ]]; then
    php -r 'echo json_encode([
      "status" => $argv[1],
      "message" => $argv[2],
      "gate_available" => filter_var($argv[3], FILTER_VALIDATE_BOOLEAN),
    ], JSON_UNESCAPED_SLASHES), "\n";' "${status}" "${message}" "${gate_available}"
  else
    echo "honest_compile_status=${status} gate_available=${gate_available} ${message}"
  fi
}

for path in \
  "${ROOT}/script/bootstrap-honest-compile-lib.sh" \
  "${ROOT}/script/bootstrap-loop-probe.sh" \
  "${ROOT}/script/bootstrap-inventory-argv-probe.sh"; do
  if [[ ! -f "${path}" ]]; then
    echo "bootstrap-honest-compile-metric: missing ${path}" >&2
    exit 1
  fi
done

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
  metric_log "check OK (BOOTSTRAP_HONEST_COMPILE_GATE + --honest-compile wired, #15603)"
  metric_emit unknown "wiring OK; run full metric without --check when LLVM available" true
  exit 0
fi

ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  metric_log "LLVM 9 not found — skip inventory argv honest probe"
  metric_emit skip "LLVM 9 unavailable; honest compile probe skipped" true
  exit 0
fi

metric_log "running inventory argv sidecar probe (#15604) for honest_compile metric..."
set +e
probe_out="$("${ROOT}/script/bootstrap-inventory-argv-probe.sh" 2>&1)"
probe_code=$?
set -e

sidecar_free=""
if grep -qE '^bootstrap-inventory-argv-probe: sidecar_free=' <<<"${probe_out}"; then
  sidecar_free="$(grep -E '^bootstrap-inventory-argv-probe: sidecar_free=' <<<"${probe_out}" | tail -n 1 | sed 's/.*sidecar_free=//')"
fi

if [[ "${sidecar_free}" == "ok" ]]; then
  metric_log "honest compile yes (sidecar_free=ok)"
  metric_emit yes "inventory argv driver links without emit-helper sidecar" true
  exit 0
fi

if [[ "${probe_code}" -eq 2 ]]; then
  metric_emit skip "LLVM 9 unavailable during inventory argv probe" true
  exit 0
fi

metric_log "honest compile no (sidecar_free=${sidecar_free:-blocked}, exit=${probe_code})"
if bootstrap_honest_compile_log_uses_sidecar_recovery "${probe_out}"; then
  metric_emit no "gen-0 sidecar recovery detected in inventory argv probe" true
else
  metric_emit no "inventory argv driver still depends on emit-helper / link-time sidecars (#15604)" true
fi
exit 0
