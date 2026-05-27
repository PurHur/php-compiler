#!/usr/bin/env bash
# Bundled Compiler.php AOT native link + run gate (issues #212, #78, #557, #579).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/compiler_minimal/main.php"
OUT="${ROOT}/build/selfhost"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
# shellcheck source=bootstrap-resolve-compile-invoke.sh
source "$(dirname "$0")/bootstrap-resolve-compile-invoke.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-link: missing ${ENTRY}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-link: LLVM 9 not found (skip)" >&2
  exit 2
fi

if ! bootstrap_resolve_compile_driver; then
  echo "bootstrap-selfhost-link: no compiled driver and php missing on host." >&2
  echo "bootstrap-selfhost-link: run via Docker instead:" >&2
  echo "  ./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-link'" >&2
  exit 1
fi

if [[ "${BOOTSTRAP_COMPILE_DRIVER_MODE}" == "zend" && -d "${ROOT}/vendor" ]]; then
  patch_log="$(mktemp)"
  if ! "${ROOT}/script/apply-patches.sh" >"${patch_log}" 2>&1; then
    echo "bootstrap-selfhost-link: apply-patches failed (#2806)" >&2
    cat "${patch_log}" >&2
    rm -f "${patch_log}"
    exit 1
  fi
  rm -f "${patch_log}"
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func"
rm -f "${OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"
if ! bootstrap_compile_invoke "${OUT}" "${ENTRY}" env PHP_COMPILER_SELFHOST_AOT=1 2>&1; then
  echo "bootstrap-selfhost-link: compile failed (progress gate; see stderr above)" >&2
  exit 1
fi
test -x "${OUT}"
out="$("${OUT}")"
test "compiler_minimal bundle OK" = "${out//$'\n'/}"
echo "bootstrap-selfhost-link: OK ${OUT}"
