#!/usr/bin/env bash
# M4 bootstrap-loop probe (issue #1498): prerequisite ladder + M4 gate scaffold.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/bootstrap_loop_smoke/main.php"
COMPILE_DRIVER="${ROOT}/test/selfhost/bootstrap_loop_smoke/compile_driver.php"
M3_PROBE="${ROOT}/script/bootstrap-selfhost-helloworld-probe.sh"
SPINE_LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
GEN1_LINK="${ROOT}/script/bootstrap-loop-gen1-link.sh"
DRY_RUN=0

usage() {
  cat <<'EOF'
Usage: script/bootstrap-loop-probe.sh [--dry-run]

M4 bootstrap-loop probe (#1498). Runs M2 spine + M3 partial + gen-1 link/gen-2 attempt,
then (unless --dry-run) M3 strict native emit and gen-1→gen-2 native slice.

Exit codes:
  0  --dry-run: lint + M2 spine + M3 partial + gen-1 link (gen-2 Zend partial OK) green
     full:      same + M3 strict + gen-1→gen-2 native emit (default on; opt-out BOOTSTRAP_M4_RUNTIME_COMPILE=0; #2599)
  1  hard failure (missing entry/scripts, lint, M2 spine, M3 partial, or gen-1 link)
  2  LLVM 9 not found (skip), or full mode: M3 strict / gen-2 native emit not ready
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

if [[ ! -f "${GEN1_LINK}" ]]; then
  echo "bootstrap-loop-probe: missing ${GEN1_LINK}" >&2
  exit 1
fi

if [[ ! -f "${COMPILE_DRIVER}" ]]; then
  echo "bootstrap-loop-probe: missing ${COMPILE_DRIVER}" >&2
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

echo "==> lint bootstrap_loop_smoke compile driver"
if ! php "${ROOT}/bin/compile.php" -l "${COMPILE_DRIVER}" 2>&1; then
  echo "bootstrap-loop-probe: compile driver lint failed (exit 1)" >&2
  exit 1
fi

if ! m4_run_subprobe "M2 lib spine smoke (native link + run)" bash "${SPINE_LINK}"; then
  echo "bootstrap-loop-probe: M2 prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke" >&2
  exit 1
fi

if ! m4_run_subprobe "M3 HelloWorld probe (partial — native emit when LLVM present)" \
  env BOOTSTRAP_M3_LINK_COMPILE_DRIVER="${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-1}" \
  BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" \
  BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" \
  bash "${M3_PROBE}"; then
  echo "bootstrap-loop-probe: M3 partial prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: make bootstrap-selfhost-helloworld" >&2
  exit 1
fi

echo ""
echo "Prerequisites OK through M3 partial (native HelloWorld run; emit_path=native when helper links)."
echo ""

GEN1_LOG="$(mktemp)"
trap 'rm -f "${GEN1_LOG}" "${M3_STRICT_OUT:-}"' EXIT
echo "==> M4 gen-1 link + gen-2 compile attempt (script defaults native emit when LLVM present; #2611)"
set +e
(
  cd "${ROOT}"
  bash "${GEN1_LINK}"
) >"${GEN1_LOG}" 2>&1
GEN1_CODE=$?
set -e

if [[ "${GEN1_CODE}" -ne 0 ]]; then
  echo "bootstrap-loop-probe: M4 gen-1 link failed (exit 1)" >&2
  m4_probe_tail "${GEN1_LOG}"
  exit 1
fi

grep -E 'bootstrap-loop-gen1-link: OK' "${GEN1_LOG}" || tail -n 5 "${GEN1_LOG}"
if grep -q 'emit_path=native' "${GEN1_LOG}"; then
  echo "bootstrap-loop-probe: gen-2 native emit OK (incremental M4 slice)"
elif grep -q 'emit_path=zend partial' "${GEN1_LOG}"; then
  if grep -q 'gen-1 native emit blocked —' "${GEN1_LOG}"; then
    m4_block="$(grep -m1 'gen-1 native emit blocked —' "${GEN1_LOG}" | sed 's/^.*gen-1 native emit blocked — //')"
    echo "bootstrap-loop-probe: gen-2 emit_path=zend partial (gen-1 native emit blocked — ${m4_block})"
  elif grep -q 'emit helper link failed' "${GEN1_LOG}"; then
    m4_block="$(grep -m1 'emit helper link failed' "${GEN1_LOG}" | sed 's/^bootstrap-loop-gen1-link: //')"
    echo "bootstrap-loop-probe: gen-2 emit_path=zend partial (${m4_block})"
  elif grep -q 'runtime gate:' "${GEN1_LOG}"; then
    m4_block="$(grep -m1 'runtime gate:' "${GEN1_LOG}" | sed 's/^bootstrap-loop-gen1-link: gen-2 emit_path=zend (bin\/compile.php) — //')"
    echo "bootstrap-loop-probe: gen-2 emit_path=zend partial (${m4_block})"
  else
    echo "bootstrap-loop-probe: gen-2 emit_path=zend partial (gen-1 native emit blocked — see gen1-link log)"
  fi
fi
echo ""

if [[ "${DRY_RUN}" -eq 1 ]]; then
  echo "bootstrap-loop-probe: --dry-run OK (exit 0)"
  echo ""
  echo "Full M4 loop (without --dry-run): M3 strict + gen-1→gen-2 native emit."
  echo "  make bootstrap-loop-probe"
  echo "  make bootstrap-selfhost-helloworld  # default native emit (strict; #2522)"
  echo "Next: gen-1 compiles bin/compile.php (or src/cli.php) → full gen-2 tree (#1467, #1521)"
  exit 0
fi

M3_STRICT_OUT="$(mktemp)"
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
  echo "bootstrap-loop-probe: hint: make bootstrap-selfhost-helloworld" >&2
  echo "bootstrap-loop-probe: use --dry-run to validate lint + spine + M3 partial without strict" >&2
  m4_probe_tail "${M3_STRICT_OUT}"
  exit 2
fi

grep -E 'bootstrap-selfhost-helloworld-probe: OK' "${M3_STRICT_OUT}" || tail -n 5 "${M3_STRICT_OUT}"

echo ""
echo "bootstrap-loop-probe: M3 strict prerequisite OK"
if grep -q 'emit_path=native' "${GEN1_LOG}" 2>/dev/null; then
  echo "bootstrap-loop-probe: M4 gen-1→gen-2 native slice OK (exit 0)"
  exit 0
fi

echo "bootstrap-loop-probe: M3 strict OK; M4 gen-2 native emit still blocked (exit 2)" >&2
echo "bootstrap-loop-probe: gen-1 link log lacked emit_path=native — check gen-1 native emit output" >&2
echo "bootstrap-loop-probe: NEXT: make bootstrap-loop-gen1-link" >&2
m4_probe_tail "${GEN1_LOG}" 5
exit 2
