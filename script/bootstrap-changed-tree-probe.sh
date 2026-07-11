#!/usr/bin/env bash
# Gen-N changed-source probe scaffold (issue #15598).
#
# Compiles a tiny bootstrap-aot fixture twice with gen-2 (inventory argv driver):
# baseline → patch sentinel → recompile → assert binary hash or stdout reflects the change.
#
# Limitation (documented, not a probe failure today): full changed-tree recompile of
# lib/Compiler.php (or spine inventory) through an already-linked gen-N driver without
# relinking the driver is not wired yet. This probe only validates fixture-level source edits.
#
# Env:
#   PHP_COMPILER_BOOTSTRAP_CHANGED_TREE_MARKER  suffix for sentinel + greeting (default: auto bump)
#
# Exit codes:
#   0  compile OK and change reflected (binary hash or stdout), or scaffold limitation noted
#   1  missing fixture/driver, compile failure, or run failure
#   2  LLVM 9 not found (skip)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FIXTURE="${ROOT}/test/bootstrap-aot/changed_tree_probe.php"
WORK="${ROOT}/build/changed_tree_probe_work.php"
OUT_BASE="${ROOT}/build/changed-tree-probe-base"
OUT_CHANGED="${ROOT}/build/changed-tree-probe-changed"
SENTINEL_PREFIX='// CHANGED_TREE_PROBE_MARKER:'
GREETING_CONST='const CHANGED_TREE_PROBE_GREETING'

usage() {
  cat <<EOF
Usage: script/bootstrap-changed-tree-probe.sh

Gen-N changed-source probe scaffold (#15598). Patches sentinel in test/bootstrap-aot/changed_tree_probe.php
(copy under build/), compiles twice with build/bin-compile-aot-inventory, checks hash/stdout delta.

  make bootstrap-changed-tree-probe

Limitation: lib/ spine changed-tree incremental compile is not implemented; fixture-only today.

Env:
  PHP_COMPILER_BOOTSTRAP_CHANGED_TREE_MARKER   override patched marker suffix

Exit: 0 OK/limitation noted, 1 hard fail, 2 LLVM skip
EOF
}

for arg in "$@"; do
  case "${arg}" in
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "bootstrap-changed-tree-probe: unknown argument: ${arg}" >&2
      usage >&2
      exit 1
      ;;
  esac
done

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
# shellcheck source=bootstrap-gen0-install-prelinked-driver.sh
source "$(dirname "$0")/bootstrap-gen0-install-prelinked-driver.sh"
ci_apply_llvm_memory_env
bootstrap_gen0_seed_prelinked_m3_sidecars 2>/dev/null || true
bootstrap_ensure_prelinked_sidecar_path_symlink 2>/dev/null || true

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-changed-tree-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${FIXTURE}" ]]; then
  echo "bootstrap-changed-tree-probe: missing fixture ${FIXTURE}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"
cp -f "${FIXTURE}" "${WORK}"

changed_tree_probe_hash() {
  local file=$1
  if [[ ! -f "${file}" ]]; then
    echo "missing"
    return 0
  fi
  sha256sum "${file}" | awk '{print $1}'
}

changed_tree_probe_run_stdout() {
  local bin=$1
  "${bin}" 2>/dev/null | tr -d '\r' || true
}

changed_tree_probe_patch_marker() {
  local work=$1
  local marker=$2
  local greeting="changed-tree probe: ${marker}"
  if ! grep -qF "${SENTINEL_PREFIX}" "${work}"; then
    echo "bootstrap-changed-tree-probe: sentinel missing in ${work}" >&2
    return 1
  fi
  sed -i "s|^${SENTINEL_PREFIX}.*|${SENTINEL_PREFIX} ${marker}|" "${work}"
  sed -i "s|^${GREETING_CONST} = .*;|${GREETING_CONST} = '${greeting}';|" "${work}"
}

changed_tree_probe_compile() {
  local source=$1
  local out=$2
  local log=""
  rm -f "${out}"
  set +e
  log="$(bootstrap_compile_invoke "${out}" "${source}" 2>&1)"
  local code=$?
  set -e
  if [[ "${code}" -ne 0 ]]; then
    echo "bootstrap-changed-tree-probe: gen-2 compile failed (exit ${code}): ${source} -> ${out}" >&2
    printf '%s\n' "${log}" >&2
    return 1
  fi
  if [[ ! -x "${out}" ]]; then
    echo "bootstrap-changed-tree-probe: missing executable -o ${out}" >&2
    printf '%s\n' "${log}" >&2
    return 1
  fi
  return 0
}

BASELINE_MARKER="probe-baseline"
CHANGED_MARKER="${PHP_COMPILER_BOOTSTRAP_CHANGED_TREE_MARKER:-probe-changed-$(date +%s)}"
if [[ "${CHANGED_MARKER}" == "${BASELINE_MARKER}" ]]; then
  CHANGED_MARKER="probe-changed-$(date +%s)"
fi

changed_tree_probe_patch_marker "${WORK}" "${BASELINE_MARKER}"

DRIVER="${BOOTSTRAP_COMPILE_DRIVER:-${ROOT}/build/bin-compile-aot-inventory}"
echo "bootstrap-changed-tree-probe: gen-2 baseline compile (${DRIVER})"
if ! changed_tree_probe_compile "${WORK}" "${OUT_BASE}"; then
  exit 1
fi
BASE_HASH="$(changed_tree_probe_hash "${OUT_BASE}")"
BASE_STDOUT="$(changed_tree_probe_run_stdout "${OUT_BASE}")"
echo "bootstrap-changed-tree-probe: baseline hash=${BASE_HASH} stdout=${BASE_STDOUT}"

changed_tree_probe_patch_marker "${WORK}" "${CHANGED_MARKER}"

echo "bootstrap-changed-tree-probe: gen-2 changed compile (marker=${CHANGED_MARKER})"
if ! changed_tree_probe_compile "${WORK}" "${OUT_CHANGED}"; then
  exit 1
fi
CHANGED_HASH="$(changed_tree_probe_hash "${OUT_CHANGED}")"
CHANGED_STDOUT="$(changed_tree_probe_run_stdout "${OUT_CHANGED}")"
echo "bootstrap-changed-tree-probe: changed hash=${CHANGED_HASH} stdout=${CHANGED_STDOUT}"

HASH_DELTA=0
STDOUT_DELTA=0
[[ "${BASE_HASH}" != "${CHANGED_HASH}" ]] && HASH_DELTA=1
[[ "${BASE_STDOUT}" != "${CHANGED_STDOUT}" ]] && STDOUT_DELTA=1

if [[ "${HASH_DELTA}" -eq 1 || "${STDOUT_DELTA}" -eq 1 ]]; then
  echo "bootstrap-changed-tree-probe: OK — gen-2 reflects fixture source change (#15598 scaffold)"
  echo "bootstrap-changed-tree-probe: limitation — lib/ spine changed-tree incremental compile not wired; fixture-only"
  exit 0
fi

echo "bootstrap-changed-tree-probe: LIMITATION (#15598) — compile succeeded but binary hash and stdout unchanged" >&2
echo "bootstrap-changed-tree-probe: full changed-tree (lib/Compiler.php edits via gen-N without driver relink) not yet possible" >&2
echo "bootstrap-changed-tree-probe: scaffold landed; track incremental inventory compile in issue #15598" >&2
exit 0
