#!/usr/bin/env bash
# M4 bootstrap-loop probe (issue #1498): prerequisite ladder + M4 gate scaffold.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/bootstrap_loop_smoke/main.php"
COMPILE_DRIVER="${ROOT}/test/selfhost/bootstrap_loop_smoke/compile_driver.php"
M3_PROBE="${ROOT}/script/bootstrap-selfhost-helloworld-probe.sh"
SELFHOST_LINK="${ROOT}/script/bootstrap-selfhost-link.sh"
SPINE_LINK="${ROOT}/script/bootstrap-selfhost-lib-spine-smoke-link.sh"
GEN1_LINK="${ROOT}/script/bootstrap-loop-gen1-link.sh"
FULL_SPINE_EMIT="${ROOT}/script/bootstrap-loop-gen1-full-spine-emit.sh"
GEN2_RECOMPILE="${ROOT}/script/bootstrap-loop-gen2-recompile-spine.sh"
DRY_RUN=0
SPINE_RATIO="725/725"

m4_spine_ratio_label() {
  local json spine
  json="$(php "${ROOT}/script/bootstrap-spine-count.php" --json 2>/dev/null)" || json='{"spine":725}'
  spine="$(php -r '$j=json_decode($argv[1],true); echo (int)($j["spine"]??725);' "${json}")"
  echo "${spine}/${spine}"
}

usage() {
  cat <<EOF
Usage: script/bootstrap-loop-probe.sh [--dry-run]

M4 bootstrap-loop probe (#1498). Runs M0 link + M2 spine + M3 HelloWorld with the same strict env as
\`make bootstrap-selfhost-helloworld\` (#2612), then gen-1 link / gen-2 attempt, then gen-2→gen-3 spine recompile.

Exit codes:
  0  --dry-run: lint + M0 link + M2 spine + M3 HelloWorld strict + gen-1 link (gen-2 Zend partial OK)
     full:      same + gen-1→gen-2 native + gen-2→gen-3 spine (${SPINE_RATIO})
  1  hard failure (missing entry/scripts, lint, M0 link, M2 spine, M3 HelloWorld, or gen-1 link)
  2  LLVM 9 not found (skip), or full mode: gen-2 native emit or gen-3 spine recompile blocked
  3  reserved

Examples:
  make bootstrap-loop-probe
  make bootstrap-loop-full-spine-probe
  ./script/bootstrap-loop-probe.sh --dry-run
  make bootstrap-selfhost-helloworld
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
# shellcheck source=selfhost-preflight.sh
source "$(dirname "$0")/selfhost-preflight.sh"
ci_apply_llvm_memory_env

selfhost_preflight bootstrap-loop-probe php-or-docker
SPINE_RATIO="$(m4_spine_ratio_label)"

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
  echo "mode: --dry-run (lint + M0 link + M2 spine + M3 HelloWorld Makefile parity — strict native emit; no gen-2 strict slice)"
else
  echo "mode: full (M3 HelloWorld strict before gen-1; gen-1→gen-2 + gen-2→gen-3 spine per #2611/#2697)"
fi
echo ""
echo "Exit codes: 0=green gate | 1=hard failure | 2=LLVM skip or M4 gen-2/gen-3 blocked | 3=reserved"
echo ""

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-loop-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${M3_PROBE}" ]]; then
  echo "bootstrap-loop-probe: missing ${M3_PROBE}" >&2
  exit 1
fi

if [[ ! -f "${SELFHOST_LINK}" ]]; then
  echo "bootstrap-loop-probe: missing ${SELFHOST_LINK}" >&2
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

if [[ ! -f "${FULL_SPINE_EMIT}" ]]; then
  echo "bootstrap-loop-probe: missing ${FULL_SPINE_EMIT}" >&2
  exit 1
fi

if [[ ! -f "${GEN2_RECOMPILE}" ]]; then
  echo "bootstrap-loop-probe: missing ${GEN2_RECOMPILE}" >&2
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

if ! m4_run_subprobe "M0 self-host native link (compiler_minimal bundle)" bash "${SELFHOST_LINK}"; then
  echo "bootstrap-loop-probe: M0 prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: make bootstrap-selfhost-link" >&2
  exit 1
fi

if ! m4_run_subprobe "M2 lib spine smoke (native link + run)" bash "${SPINE_LINK}"; then
  echo "bootstrap-loop-probe: M2 prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke" >&2
  exit 1
fi

if ! m4_run_subprobe "M3 HelloWorld probe (Makefile parity — strict native emit, #2612)" \
  env BOOTSTRAP_M3_LINK_COMPILE_DRIVER="${BOOTSTRAP_M3_LINK_COMPILE_DRIVER:-1}" \
  BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1}" \
  BOOTSTRAP_M3_RUNTIME_COMPILE="${BOOTSTRAP_M3_RUNTIME_COMPILE:-1}" \
  BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
  bash "${M3_PROBE}"; then
  echo "bootstrap-loop-probe: M3 HelloWorld prerequisite failed (exit 1)" >&2
  echo "bootstrap-loop-probe: hint: make bootstrap-selfhost-helloworld" >&2
  exit 1
fi

echo ""
echo "Prerequisites OK through M3 HelloWorld (strict; same env as Makefile bootstrap-selfhost-helloworld)."
echo ""

GEN1_LOG="$(mktemp)"
trap 'rm -f "${GEN1_LOG}"' EXIT
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
  echo "Full M4 loop (without --dry-run): same M3 HelloWorld strict + gen-1→gen-2 native + gen-2→gen-3 spine."
  echo "  make bootstrap-loop-probe"
  echo "  make bootstrap-loop-gen2-recompile-spine   # gen-2→gen-3 only (after gen-1→gen-2 spine)"
  echo "  make bootstrap-selfhost-helloworld  # same strict env as the M3 step here (#2612)"
  echo "See docs/bootstrap-generations.md for generation ladder."
  exit 0
fi

echo "==> M4 exit status (M3 HelloWorld strict already verified above)"
if grep -q 'emit_path=native' "${GEN1_LOG}" 2>/dev/null; then
  echo "bootstrap-loop-probe: M4 gen-1→gen-2 native slice OK"
  if [[ "${BOOTSTRAP_M4_GEN1_COMPILE_FULL_SPINE:-0}" == "1" ]]; then
    echo ""
    if ! m4_run_subprobe "M4 gen-1 full-spine native emit (compiler_lib_spine_smoke, #2664)" \
      env BOOTSTRAP_M4_LINK_COMPILE_DRIVER="${BOOTSTRAP_M4_LINK_COMPILE_DRIVER:-1}" \
      BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING="${BOOTSTRAP_M4_COMPILE_DRIVER_REAL_LOWERING:-1}" \
      BOOTSTRAP_M4_RUNTIME_COMPILE="${BOOTSTRAP_M4_RUNTIME_COMPILE:-1}" \
      bash "${FULL_SPINE_EMIT}"; then
      echo "bootstrap-loop-probe: M4 full-spine gen-1 emit blocked (exit 2)" >&2
      exit 2
    fi
  fi
  echo ""
  if ! m4_run_subprobe "M4 gen-2→gen-3 spine recompile (${SPINE_RATIO}, #2697)" bash "${GEN2_RECOMPILE}"; then
    echo "bootstrap-loop-probe: M4 gen-2→gen-3 spine recompile blocked (exit 2)" >&2
    exit 2
  fi
  echo "bootstrap-loop-probe: M4 full ladder OK — gen-1→gen-2 native + gen-2→gen-3 spine (exit 0)"
  exit 0
fi

echo "bootstrap-loop-probe: M3 HelloWorld strict OK; M4 gen-2 native emit still blocked (exit 2)" >&2
echo "bootstrap-loop-probe: gen-1 link log lacked emit_path=native — check gen-1 native emit output" >&2
echo "bootstrap-loop-probe: NEXT: make bootstrap-loop-gen1-link" >&2
m4_probe_tail "${GEN1_LOG}" 5
exit 2
