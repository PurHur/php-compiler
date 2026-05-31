#!/usr/bin/env bash
# 003-MiniWebApp VM OOP acceptance: lint zero + phpc serve PATH_INFO curls (issues #2059, #2189).
#
# Usage:
#   ./script/check-miniwebapp-vm-oop.sh
#   MINIWEBAPP_VM_OOP_GATE=1 ./script/ci-fast.sh
#
# Requires loopback TCP (same as examples-web-smoke). Fails when PHP_COMPILER_SKIP_SERVE_TESTS is set.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
MINIWEBAPP=examples/003-MiniWebApp

if [[ ! -d "${MINIWEBAPP}/public" ]]; then
  echo "check-miniwebapp-vm-oop: missing ${MINIWEBAPP}/public" >&2
  exit 1
fi

if [[ -n "${PHP_COMPILER_SKIP_SERVE_TESTS:-}" ]]; then
  echo "check-miniwebapp-vm-oop: PHP_COMPILER_SKIP_SERVE_TESTS is set (unset to run VM OOP serve smoke)" >&2
  exit 1
fi

# shellcheck source=ci-common.sh
source "$(dirname "$0")/ci-common.sh"
if ! ci_can_bind_loopback; then
  echo "check-miniwebapp-vm-oop: cannot bind loopback TCP (serve smoke unavailable)" >&2
  exit 1
fi

echo "check-miniwebapp-vm-oop: lint zero unsupported nodes (#2078)..."
"$PHP_BIN" "${PHP_OPTS[@]}" script/check-miniwebapp-lint-zero.php

echo "check-miniwebapp-vm-oop: VM phpc serve PATH_INFO curls (#2059, #633)..."
"${ROOT}/script/examples-web-smoke.sh" --miniwebapp-only

echo "check-miniwebapp-vm-oop: OK"
