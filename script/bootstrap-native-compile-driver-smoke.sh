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

# Stage 2: prove the compiled driver can lint a bootstrap fixture without Zend.
set +e
lint_out="$("${PHP_COMPILER_M3_OUT}" -l "${ROOT}/test/bootstrap-aot/echo_hello.php" 2>&1)"
lint_code=$?
set -e
printf '%s\n' "${lint_out}"
if [[ "${lint_code}" -ne 0 ]]; then
  echo "bootstrap-native-compile-driver-smoke: compiled bin/compile.php lint failed (exit ${lint_code})" >&2
  exit "${lint_code}"
fi

echo "bootstrap-native-compile-driver-smoke: OK ${PHP_COMPILER_M3_OUT} -l test/bootstrap-aot/echo_hello.php"
