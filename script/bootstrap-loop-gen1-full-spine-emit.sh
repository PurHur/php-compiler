#!/usr/bin/env bash
# M4 probe: gen-1 emit helper native-emits the full compiler_lib_spine_smoke bundle (issue #2664).
#
# This is an opt-in probe: it does not run in the default bootstrap-loop slice, because it is inventory-scale
# (717 units) and will surface new emit-TU init/runtime issues as M4 work progresses.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

FULL_SPINE_ENTRY="test/selfhost/compiler_lib_spine_smoke/main.php"
EMIT_ENTRY="test/selfhost/compiler_helloworld_smoke/compile_driver.php"
EMIT_ENTRY_ABS="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
EMIT_HELPER="build/bootstrap-loop-gen1-full-spine-emit-helper"
GEN2_OUT="build/bootstrap-loop-gen2-full-spine"

if [[ ! -f "${FULL_SPINE_ENTRY}" ]]; then
  echo "bootstrap-loop-gen1-full-spine-emit: missing ${ROOT}/${FULL_SPINE_ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${EMIT_ENTRY}" ]]; then
  echo "bootstrap-loop-gen1-full-spine-emit: missing ${ROOT}/${EMIT_ENTRY} (#1983)" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-gen1-full-spine-emit: LLVM 9 not found (skip)" >&2
  exit 2
fi

mkdir -p build
rm -f "${EMIT_HELPER}" "${GEN2_OUT}" "build/.last-jit-func-bootstrap-loop-gen1-full-spine-emit" "build/.last-jit-func-bootstrap-loop-gen1-full-spine-emit-helper"
export PHP_COMPILER_SELFHOST_AOT=1

# Link emit helper as inventory compile_driver (mirror bootstrap-loop-gen1-link.sh; #3032).
export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1-full-spine-emit-helper"
echo "==> link gen-1 full-spine emit helper"
if ! env PHP_COMPILER_SELFHOST_AOT=1 \
  PHP_COMPILER_M3_COMPILE_DRIVER=1 \
  PHP_COMPILER_EMIT_HELPER_LINK=1 \
  PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1 \
  PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
  BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1 \
  PHP_COMPILER_M3_EMIT_LOG_PREFIX=helloworld_compile_smoke \
  php bin/compile.php -o "${EMIT_HELPER}" "${EMIT_ENTRY_ABS}" >/dev/null 2>&1; then
  echo "bootstrap-loop-gen1-full-spine-emit: emit helper link failed (see build/.last-jit-func-bootstrap-loop-gen1-full-spine-emit-helper)" >&2
  exit 1
fi
test -x "${EMIT_HELPER}"

export PHP_COMPILER_JIT_PROGRESS_FILE="build/.last-jit-func-bootstrap-loop-gen1-full-spine-emit"
echo "==> gen-1 native emit full spine (compiler_lib_spine_smoke)"
set +e
compile_out="$(
  env PHP_COMPILER_M3_COMPILE_MODE=compile \
    PHP_COMPILER_M3_RUNTIME_COMPILE=1 \
    PHP_COMPILER_M3_EMIT_MINIMAL=1 \
    PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1 \
    PHP_COMPILER_M3_SOURCE="${ROOT}/${FULL_SPINE_ENTRY}" \
    PHP_COMPILER_M3_OUT="${ROOT}/${GEN2_OUT}" \
    "./${EMIT_HELPER}" 2>&1
)"
compile_code=$?
set -e

if [[ "${compile_code}" -ne 0 ]]; then
  echo "bootstrap-loop-gen1-full-spine-emit: gen-1 native emit failed (exit ${compile_code})" >&2
  if [[ -n "${PHP_COMPILER_JIT_PROGRESS_FILE:-}" && -f "${ROOT}/${PHP_COMPILER_JIT_PROGRESS_FILE}" ]]; then
    echo "bootstrap-loop-gen1-full-spine-emit: last JIT func: $(tail -1 "${ROOT}/${PHP_COMPILER_JIT_PROGRESS_FILE}")" >&2
  fi
  if grep -qE 'LogicException|Unsupported ' <<< "${compile_out}"; then
    grep -m1 -E 'LogicException|Unsupported ' <<< "${compile_out}" >&2 || true
  fi
  printf '%s\n' "${compile_out}" >&2
  exit 1
fi

if ! grep -qE 'compile_smoke_m3_emit: compile OK|bootstrap_loop_compile_smoke: gen-2 compile OK|helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-loop-gen1-full-spine-emit: unexpected emit helper stdout (missing compile OK marker)" >&2
  printf '%s\n' "${compile_out}" >&2
  exit 1
fi

if [[ ! -x "${GEN2_OUT}" ]]; then
  echo "bootstrap-loop-gen1-full-spine-emit: missing gen-2 executable ${ROOT}/${GEN2_OUT}" >&2
  exit 1
fi

echo "==> gen-2 smoke (full spine binary)"
gen2_out="$("./${GEN2_OUT}" 2>&1)"
if ! grep -q 'compiler_lib_spine_smoke bundle OK' <<< "${gen2_out}"; then
  echo "bootstrap-loop-gen1-full-spine-emit: unexpected gen-2 stdout (want compiler_lib_spine_smoke bundle OK)" >&2
  printf '%s\n' "${gen2_out}" >&2
  exit 1
fi

echo "bootstrap-loop-gen1-full-spine-emit: OK emit_path=native gen-2=${ROOT}/${GEN2_OUT}"
