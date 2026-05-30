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
if [[ "${BOOTSTRAP_NATIVE_COMPILE_DRIVER_SKIP_LINK:-0}" != "1" ]]; then
  rm -f "${PHP_COMPILER_M3_OUT}"
  # Build the emit-helper compile driver explicitly (argv `-o OUT SOURCE.php`).
  EMIT_ENTRY="${ROOT}/test/selfhost/compiler_helloworld_smoke/compile_driver.php"
  env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 \
    php "${ROOT}/bin/compile.php" -o "${PHP_COMPILER_M3_OUT}" "${EMIT_ENTRY}" >/dev/null
fi

if [[ ! -x "${PHP_COMPILER_M3_OUT}" ]]; then
  echo "bootstrap-native-compile-driver-smoke: expected executable ${PHP_COMPILER_M3_OUT}" >&2
  exit 1
fi

# Stage 2: native driver compiles via argv `bin/compile.php -o` shape (#1937, #2697).
AOT_OUT="${ROOT}/build/bootstrap-native-driver-smoke"
SMOKE_SOURCE="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
rm -f "${AOT_OUT}"
set +e
compile_out="$(
  env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
    "${PHP_COMPILER_M3_OUT}" -o "${AOT_OUT}" "${SMOKE_SOURCE}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"
if [[ "${compile_code}" -ne 0 ]] || [[ ! -x "${AOT_OUT}" ]]; then
  echo "bootstrap-native-compile-driver-smoke: argv -o compile failed (exit ${compile_code})" >&2
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

echo "bootstrap-native-compile-driver-smoke: OK argv -o (${PHP_COMPILER_M3_OUT})"
