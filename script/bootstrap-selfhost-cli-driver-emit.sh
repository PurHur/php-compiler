#!/usr/bin/env bash
# M5: native emit bin/vm.php and src/cli_driver.php via helloworld compile driver (#2699).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-selfhost-cli-driver-emit: LLVM 9 not found (skip)" >&2
  exit 2
fi

HELPER="${ROOT}/build/selfhost-helloworld-compile"
if [[ ! -x "${HELPER}" ]]; then
  "${ROOT}/script/bootstrap-selfhost-helloworld-compile-bin.sh" >/dev/null
fi
if [[ ! -x "${HELPER}" ]]; then
  echo "bootstrap-selfhost-cli-driver-emit: missing ${HELPER} (link helloworld compile driver first)" >&2
  exit 1
fi

fail=0
for rel in bin/vm.php src/cli_driver.php; do
  base="$(basename "${rel}" .php)"
  aot_out="${ROOT}/build/cli-driver-emit-${base}"
  rm -f "${aot_out}"
  set +e
  out="$(
    env PHP_COMPILER_M3_COMPILE_MODE=compile \
      PHP_COMPILER_M3_RUNTIME_COMPILE=1 \
      PHP_COMPILER_M3_SOURCE="${ROOT}/${rel}" \
      PHP_COMPILER_M3_OUT="${aot_out}" \
      "${HELPER}" 2>&1
  )"
  code=$?
  set -e
  printf '%s\n' "${out}"
  if [[ "${code}" -ne 0 ]] || ! grep -q 'helloworld_compile_smoke: compile OK' <<< "${out}"; then
    echo "bootstrap-selfhost-cli-driver-emit: FAIL ${rel}" >&2
    fail=1
    continue
  fi
  if [[ ! -x "${aot_out}" ]]; then
    echo "bootstrap-selfhost-cli-driver-emit: missing AOT output ${aot_out}" >&2
    fail=1
    continue
  fi
  echo "bootstrap-selfhost-cli-driver-emit: OK ${rel} -> ${aot_out}"
done

if [[ "${fail}" -ne 0 ]]; then
  exit 1
fi

echo "bootstrap-selfhost-cli-driver-emit: emit_path=native (vm + cli_driver)"
