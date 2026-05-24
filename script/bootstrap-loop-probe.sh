#!/usr/bin/env bash
# M4 bootstrap-loop probe (scaffold — issue #1498): gate definition only; loop not implemented.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENTRY="${ROOT}/test/selfhost/bootstrap_loop_smoke/main.php"
M3_PROBE="${ROOT}/script/bootstrap-selfhost-helloworld-probe.sh"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

echo "=== M4 bootstrap-loop probe (scaffold #1498) ==="
echo ""
echo "Prerequisites (M4 blocked until these are green):"
echo "  - M3 native emit: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld"
echo "  - M2 spine: BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke"
echo "  - LLVM 9 at PHP_COMPILER_LLVM_PATH (libLLVM-9.so.1)"
echo ""
echo "Future green gate (not implemented — #1498 follow-up):"
echo "  1. Link gen-1 native binary from test/selfhost/bootstrap_loop_smoke/main.php"
echo "  2. gen-1 runs compiled bin/compile.php (or src/cli.php shim) on this tree → gen-2 binary"
echo "  3. gen-2 compiles compile_smoke / HelloWorld without Zend emit"
echo "  4. gen-1 and gen-2 produce matching artifacts (bootstrap loop closed)"
echo ""

if [[ ! -f "${ENTRY}" ]]; then
  echo "bootstrap-loop-probe: missing ${ENTRY}" >&2
  exit 1
fi

if [[ ! -f "${M3_PROBE}" ]]; then
  echo "bootstrap-loop-probe: missing ${M3_PROBE}" >&2
  exit 1
fi

if [[ -z "${PHP_COMPILER_LLVM_PATH:-}" || ! -f "${PHP_COMPILER_LLVM_PATH}/libLLVM-9.so.1" ]]; then
  echo "bootstrap-loop-probe: LLVM 9 not found (skip)" >&2
  exit 2
fi

echo "==> lint bootstrap_loop_smoke bundle entry"
if ! php "${ROOT}/bin/compile.php" -l "${ENTRY}" 2>&1; then
  echo "bootstrap-loop-probe: lint failed" >&2
  exit 1
fi

echo "==> M3 native-emit prerequisite (strict)"
M3_OUT="$(mktemp)"
trap 'rm -f "${M3_OUT}"' EXIT
set +e
(
  cd "${ROOT}"
  BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
  BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
  BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
  "${M3_PROBE}"
) >"${M3_OUT}" 2>&1
M3_CODE=$?
set -e

if [[ "${M3_CODE}" -ne 0 ]]; then
  echo "bootstrap-loop-probe: M3 native emit not ready — M4 blocked (exit 2)" >&2
  echo "bootstrap-loop-probe: close M3 first (#1402, docs/bootstrap-m5-fast-path.md)" >&2
  echo "bootstrap-loop-probe: hint: BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld" >&2
  echo "--- M3 strict probe tail ---" >&2
  tail -n 8 "${M3_OUT}" >&2
  exit 2
fi

echo "bootstrap-loop-probe: M3 prerequisite OK"
echo "bootstrap-loop-probe: scaffold OK — gen-1→gen-2 rebuild not implemented (#1498)"
echo "bootstrap-loop-probe: NEXT: native gen-1 compiles compiler tree → gen-2 binary"
