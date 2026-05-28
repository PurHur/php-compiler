#!/usr/bin/env bash
# M5 repro: native compile via helloworld compile_driver (not emit-TU — #2681).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/bootstrap-aot/helloworld_compile_m3_emit_native_entry.php"
OUT="${ROOT}/build/selfhost-helloworld-compile"
SOURCE="${PHP_COMPILER_M3_SOURCE:-${ROOT}/examples/000-HelloWorld/example.php}"
AOT_OUT="${PHP_COMPILER_M3_OUT:-${ROOT}/build/helloworld-compile-bin-aot}"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: LLVM 9 not found (skip)" >&2
  exit 2
fi

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: missing ${ENTRY}" >&2
  exit 1
fi

mkdir -p "${ROOT}/build"
export PHP_COMPILER_SELFHOST_AOT=1
export PHP_COMPILER_M3_COMPILE_DRIVER=1
export PHP_COMPILER_EMIT_HELPER_LINK=1

SOURCE_NORM="${SOURCE}"
if [[ "${SOURCE}" != /* ]]; then
  SOURCE_NORM="${ROOT}/${SOURCE#./}"
fi

# M5: native bin/compile.php driver — link emit TU only (helloworld link first breaks argv -o — #1937).
if [[ "${SOURCE_NORM}" == "${ROOT}/bin/compile.php" ]]; then
  EMIT_ENTRY="${ROOT}/test/bootstrap-aot/compile_smoke_m3_emit_native_entry.php"
  EMIT_HELPER="${ROOT}/build/selfhost-native-compile-driver"
  rm -f "${EMIT_HELPER}" "${AOT_OUT}" "${ROOT}/build/.last-jit-func-native-compile-driver" "${ROOT}/build/.m3_bin_compile_aot_blob"
  export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-native-compile-driver"
  if ! env PHP_COMPILER_SELFHOST_AOT=1 PHP_COMPILER_M3_COMPILE_DRIVER=1 PHP_COMPILER_EMIT_HELPER_LINK=1 \
    php "${ROOT}/bin/compile.php" -o "${AOT_OUT}" "${EMIT_ENTRY}" 2>&1; then
    echo "bootstrap-selfhost-helloworld-compile-bin: native compile driver link failed" >&2
    exit 1
  fi
  if [[ ! -x "${AOT_OUT}" ]]; then
    echo "bootstrap-selfhost-helloworld-compile-bin: missing ${AOT_OUT}" >&2
    exit 1
  fi
  if [[ ! -s "${ROOT}/build/.m3_bin_compile_aot_blob" ]]; then
    echo "bootstrap-selfhost-helloworld-compile-bin: missing build/.m3_bin_compile_aot_blob (bin/compile.php sidecar; #2827)" >&2
    exit 1
  fi
  SELFTEST_SRC="${ROOT}/test/bootstrap-aot/compiler_smoke_standalone.php"
  SELFTEST_OUT="${ROOT}/build/.m3-driver-argv-selftest"
  rm -f "${SELFTEST_OUT}"
  set +e
  selftest_out="$(
    env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT \
      "${AOT_OUT}" -o "${SELFTEST_OUT}" "${SELFTEST_SRC}" 2>&1
  )"
  selftest_code=$?
  set -e
  if [[ "${selftest_code}" -ne 0 ]] || [[ ! -x "${SELFTEST_OUT}" ]] \
    || ! grep -qE 'compile_smoke_m3_emit: compile OK' <<< "${selftest_out}"; then
    echo "bootstrap-selfhost-helloworld-compile-bin: argv -o self-test failed (exit ${selftest_code})" >&2
    printf '%s\n' "${selftest_out}" >&2
    exit 1
  fi
  rm -f "${SELFTEST_OUT}"
  cp -f "${AOT_OUT}" "${EMIT_HELPER}"
  cp -f "${AOT_OUT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
  chmod +x "${EMIT_HELPER}" "${AOT_OUT}" "${ROOT}/build/.m3_bin_compile_aot_blob"
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${AOT_OUT} (M3 emit helper; argv -o OUT SOURCE.php; sidecar #2880)"
  exit 0
fi

export PHP_COMPILER_JIT_PROGRESS_FILE="${ROOT}/build/.last-jit-func-helloworld-compile-bin"
rm -f "${OUT}" "${AOT_OUT}" "${PHP_COMPILER_JIT_PROGRESS_FILE}"

set +e
php "${ROOT}/bin/compile.php" -o "${OUT}" "${ENTRY}" >/dev/null 2>&1
link_code=$?
set -e
if [[ ! -x "${OUT}" ]]; then
  echo "bootstrap-selfhost-helloworld-compile-bin: link failed (exit ${link_code})" >&2
  exit 1
fi
echo "bootstrap-selfhost-helloworld-compile-bin: link OK ${OUT}"

set +e
compile_out="$(
  env PHP_COMPILER_M3_COMPILE_MODE=compile \
    PHP_COMPILER_M3_RUNTIME_COMPILE=1 \
    PHP_COMPILER_M3_SOURCE="${SOURCE}" \
    PHP_COMPILER_M3_OUT="${AOT_OUT}" \
    "${OUT}" 2>&1
)"
compile_code=$?
set -e
printf '%s\n' "${compile_out}"

if [[ "${compile_code}" -eq 0 ]] && grep -qE 'helloworld_compile_smoke: compile OK|compile_smoke_m3_emit: compile OK' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: OK ${OUT} -> ${AOT_OUT}"
  exit 0
fi

if grep -q 'helloworld_compile_smoke:' <<< "${compile_out}"; then
  echo "bootstrap-selfhost-helloworld-compile-bin: compile blocked (honest helloworld_compile_smoke prefix — not emit-TU)" >&2
  exit 1
fi

echo "bootstrap-selfhost-helloworld-compile-bin: unexpected output (want helloworld_compile_smoke: prefix)" >&2
exit 1
