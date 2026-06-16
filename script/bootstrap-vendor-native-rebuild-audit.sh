#!/usr/bin/env bash
# M5 vendor prelink native rebuild audit — compare committed .o vs sources rebuild (#8718).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=php-env.sh
source "$(dirname "$0")/php-env.sh"
ci_apply_llvm_memory_env

export BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=0
export BOOTSTRAP_GEN0_ZEND_ONLY=0

"$PHP_BIN" "${PHP_OPTS[@]}" "${ROOT}/script/bootstrap-vendor-native-rebuild-audit.php" "$@"
