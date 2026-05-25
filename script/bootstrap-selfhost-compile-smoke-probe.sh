#!/usr/bin/env bash
# M3 compile-smoke self-host probe (issues #1056, #1492, #1937): link bundle, emit standalone echo, run natively.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUNDLE_ENTRY="${ROOT}/test/selfhost/compiler_compile_smoke/main.php"
SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
PROBE="${ROOT}/build/selfhost-compile-smoke-probe"
AOT_OUT="${ROOT}/build/compile-smoke-aot"
M3_EMIT_PATH="none"
M3_BLOCK_REASON="native emit not implemented for compiler_compile_smoke bundle (#1937)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ ! -f "${BUNDLE_ENTRY}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing ${BUNDLE_ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing ${SOURCE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-compile-smoke-probe"
rm -f "${PROBE}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

if ! php "${ROOT}/bin/compile.php" -o "${PROBE}" "${BUNDLE_ENTRY}" 2>&1; then
  echo "bootstrap-selfhost-compile-smoke-probe: link bundle failed (see stderr above)" >&2
  exit 1
fi
test -x "${PROBE}"

bundle_out="$("${PROBE}")"
if ! grep -q 'compiler_compile_smoke bundle OK' <<< "${bundle_out}"; then
  echo "bootstrap-selfhost-compile-smoke-probe: unexpected bundle stdout (want compiler_compile_smoke bundle OK)" >&2
  printf '%s\n' "${bundle_out}" >&2
  exit 1
fi

# Native emit via selfhost bundle not yet wired (M3 compile-driver spine); Zend fallback for partial gate.
if [[ "${BOOTSTRAP_M3_COMPILE_SMOKE_STRICT:-0}" == "1" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 — require native emit; refusing Zend compile.php fallback" >&2
  echo "bootstrap-selfhost-compile-smoke-probe: emit_path=zend_fallback_would_be_used block_reason=${M3_BLOCK_REASON}" >&2
  exit 1
fi

M3_EMIT_PATH="zend"
echo "bootstrap-selfhost-compile-smoke-probe: emit_path=zend (bin/compile.php) — compile-smoke AOT until native driver lands (#1937)" >&2
rm -f "${AOT_OUT}"
if ! php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${SOURCE}" 2>&1; then
  echo "bootstrap-selfhost-compile-smoke-probe: Zend compile-smoke emit failed (emit_path=zend)" >&2
  exit 1
fi

if [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-selfhost-compile-smoke-probe: missing executable ${AOT_OUT} (emit_path=${M3_EMIT_PATH})" >&2
  exit 1
fi

run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${run_out}"; then
  echo "bootstrap-selfhost-compile-smoke-probe: unexpected AOT stdout (want compiler smoke, emit_path=${M3_EMIT_PATH})" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-selfhost-compile-smoke-probe: OK emit_path=${M3_EMIT_PATH} ${PROBE} -> ${AOT_OUT}"
printf 'compile-smoke-aot stdout: %s\n' "${run_out}"
