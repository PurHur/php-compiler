#!/usr/bin/env bash
# M5 slice: compile production driver (bin/compile.php) via a native compile driver.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# shellcheck source=php-env.sh
source "${ROOT}/script/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-native-compile-driver-smoke: LLVM 9 not found (skip)" >&2
  exit 2
fi

# Stage 1: build a native compile driver (Zend links the driver; driver does native compile).
export PHP_COMPILER_M3_SOURCE="${ROOT}/bin/compile.php"
export PHP_COMPILER_M3_OUT="${ROOT}/build/bin-compile-aot"
rm -f "${PHP_COMPILER_M3_OUT}"
./script/bootstrap-selfhost-helloworld-compile-bin.sh >/dev/null

if [[ ! -x "${PHP_COMPILER_M3_OUT}" ]]; then
  echo "bootstrap-native-compile-driver-smoke: expected executable ${PHP_COMPILER_M3_OUT}" >&2
  exit 1
fi

# Stage 2: native driver compiles a fixture via M3 emit helper (#2697).
AOT_OUT="${ROOT}/build/bootstrap-native-driver-smoke"
SMOKE_SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
rm -f "${AOT_OUT}"
set +e
compile_out="$(
  env PHP_COMPILER_M3_SOURCE="${SMOKE_SOURCE}" \
    PHP_COMPILER_M3_OUT="${AOT_OUT}" \
    "${PHP_COMPILER_M3_OUT}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"
if [[ "${compile_code}" -ne 0 ]] || [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-native-compile-driver-smoke: native driver compile failed (exit ${compile_code})" >&2
  exit "${compile_code:-1}"
fi
if ! grep -qE 'compile_smoke_m3_emit: compile OK|helloworld_compile_smoke: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-native-compile-driver-smoke: missing compile OK marker" >&2
  exit 1
fi
run_out="$("${AOT_OUT}" 2>&1)"
if ! grep -q 'compiler smoke' <<< "${run_out}"; then
  echo "bootstrap-native-compile-driver-smoke: unexpected AOT stdout (want compiler smoke)" >&2
  printf '%s\n' "${run_out}" >&2
  exit 1
fi

echo "bootstrap-native-compile-driver-smoke: OK ${PHP_COMPILER_M3_OUT} -> ${AOT_OUT}"
