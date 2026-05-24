#!/usr/bin/env bash
# M4 bootstrap-loop probe (issue #1498): prerequisite ladder + M4 gate scaffold.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/bootstrap_loop_smoke/main.php"
M3_PROBE="${ROOT}/script/bootstrap-selfhost-helloworld-probe.sh"
SPINE_LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: script/bootstrap-loop-probe.sh [--dry-run]

M4 bootstrap-loop probe (#1498). Runs M2 spine smoke + M3 HelloWorld probe in sequence,
then (unless --dry-run) M3 strict native emit. Gen-1→gen-2 rebuild is not implemented yet.

Exit codes:
  0  --dry-run: lint + M2 spine + M3 partial (Zend emit OK) green
     full:      same prerequisites + BOOTSTRAP_M3_HELLOWORLD_STRICT=1 green (loop scaffold ready)
  1  hard failure (missing entry/scripts, lint, M2 spine, or M3 partial probe)
  2  LLVM 9 not found (skip), or full mode: M3 strict native emit not ready (M4 blocked)
  3  reserved

Examples:
  make bootstrap-loop-probe
  ./script/bootstrap-loop-probe.sh --dry-run
  BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld
EOF
}

for arg in "$@"; do
  case "${arg}" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "bootstrap-loop-probe: unknown argument: ${arg}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

m4_probe_tail() {
  local file=$1
  local n=${2:-8}
  if [[ -f "${file}" && -s "${file}" ]]; then
    echo "--- tail (last ${n} lines) ---" >&2
    tail -n "${n}" "${file}" >&2
  fi
}

m4_run_subprobe() {
  local label=$1
  shift
  local log
  log="$(mktemp)"
  echo "==> ${label}"
  set +e
  (cd "${ROOT}" && "$@") >"${log}" 2>&1
  local code=$?
  set -e
  if [[ "${code}" -eq 0 ]]; then
    grep -E ':( OK| OK )|probe: OK|link: OK' "${log}" || tail -n 3 "${log}"
    rm -f "${log}"
    return 0
  fi
  echo "bootstrap-loop-probe: ${label} failed (exit ${code})" >&2
  m4_probe_tail "${log}"
  rm -f "${log}"
  return "${code}"
}

echo "=== M4 bootstrap-loop probe (#1498) ==="
if [[ "${DRY_RUN}" -eq 1 ]]; then
  echo "mode: --dry-run (lint + M2 spine + M3 partial; no M3 strict native emit)"
else
  echo "mode: full (requires BOOTSTRAP_M3_HELLOWORLD_STRICT=1 after prerequisite ladder)"
fi
echo ""
echo "Exit codes: 0=green gate | 1=hard failure | 2=LLVM skip or M3 strict blocks M4 | 3=reserved"
echo ""

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-loop-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${M3_PROBE}" ]]; then
  echo "bootstrap-loop-probe: missing ${M3_PROBE}" >&2
  exit 1
fi

if [[ ! -f "${SPINE_LINK}" ]]; then
  echo "bootstrap-loop-probe: missing ${SPINE_LINK}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-probe: LLVM 9 not found at PHP_COMPILER_LLVM_PATH (exit 2)" >&2
  echo "bootstrap-loop-probe: install LLVM 9 (script/install-llvm9.sh) or set PHP_COMPILER_LLVM_PATH" >&2
  exit 2
fi

echo "==> lint bootstrap_loop_smoke bundle entry"
if ! php "${ROOT}/bin/compile.php" -l "${ENTRY}" 2>&1; then
  echo "bootstrap-loop-probe: lint failed (exit 1)" >&2
  exit 1
fi

if ! m4_run_subprobe "M2 lib spine smoke (native link + run)" bash "${SPINE_LINK}"; then
  echo "bootstrap-loop-probe: M2 prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke" >&2
  exit 1
fi

if ! m4_run_subprobe "M3 HelloWorld probe (partial — Zend emit allowed)" bash "${M3_PROBE}"; then
  echo "bootstrap-loop-probe: M3 partial prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: make bootstrap-selfhost-helloworld" >&2
  exit 1
fi

echo ""
echo "Prerequisites OK through M3 partial (native HelloWorld run; emit may be Zend)."
echo ""

if [[ "${DRY_RUN}" -eq 1 ]]; then
  echo "bootstrap-loop-probe: --dry-run OK (exit 0)"
  echo ""
  echo "Current blockers for full M4 loop (not run in --dry-run):"
  echo "  1. M3 strict native emit:"
  echo "       BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \\"
  echo "       BOOTSTRAP_M3_RUNTIME_COMPILE=1 \\"
  echo "       BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld"
  echo "     Tracker: #1402, docs/bootstrap-m5-fast-path.md"
  echo "  2. M4 gen-1→gen-2 rebuild (not implemented — #1498):"
  echo "       Link gen-1 from test/selfhost/bootstrap_loop_smoke/main.php"
  echo "       gen-1 compiles bin/compile.php (or src/cli.php) → gen-2 binary"
  echo "       gen-2 compiles compile_smoke / HelloWorld without Zend emit"
  echo "       gen-1 and gen-2 produce matching artifacts"
  exit 0
fi

M3_STRICT_OUT="$(mktemp)"
trap 'rm -f "${M3_STRICT_OUT}"' EXIT
echo "==> M3 native-emit prerequisite (strict)"
set +e
(
  cd "${ROOT}"
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
  BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
  BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
  bash "${M3_PROBE}"
) >"${M3_STRICT_OUT}" 2>&1
M3_CODE=$?
set -e

if [[ "${M3_CODE}" -ne 0 ]]; then
  echo "bootstrap-loop-probe: M3 strict native emit not ready — M4 blocked (exit 2)" >&2
  echo "bootstrap-loop-probe: close M3 first (#1402, docs/bootstrap-m5-fast-path.md)" >&2
  echo "bootstrap-loop-probe: hint: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld" >&2
  echo "bootstrap-loop-probe: use --dry-run to validate lint + spine + M3 partial without strict" >&2
  m4_probe_tail "${M3_STRICT_OUT}"
  exit 2
fi

grep -E 'bootstrap-selfhost-helloworld-probe: OK' "${M3_STRICT_OUT}" || tail -n 5 "${M3_STRICT_OUT}"

echo ""
echo "bootstrap-loop-probe: M3 strict prerequisite OK (exit 0)"
echo "bootstrap-loop-probe: scaffold OK — gen-1→gen-2 rebuild not implemented (#1498)"
echo "bootstrap-loop-probe: NEXT: native gen-1 compiles compiler tree → gen-2 binary"
exit 0
