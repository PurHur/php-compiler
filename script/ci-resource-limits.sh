#!/usr/bin/env bash
# Apply virtual-memory cap for CI shells (issue #436, #497).
# Child processes (compile.php, jit.php, vm.php) inherit the shell ulimit at fork time.
# Default PHP_COMPILER_CI_RAM_GB is in script/ci-defaults.env.

_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=ci-defaults.env
source "$_SCRIPT_DIR/ci-defaults.env"

ci_apply_resource_limits() {
  local gb="${PHP_COMPILER_CI_RAM_GB}"
  if [[ "$gb" == "0" ]]; then
    echo "CI resource limits disabled (PHP_COMPILER_CI_RAM_GB=0)."
    return 0
  fi
  if ! [[ "$gb" =~ ^[0-9]+$ ]] || [[ "$gb" -lt 1 ]]; then
    echo "CI resource limits: invalid PHP_COMPILER_CI_RAM_GB=${gb}; skipping ulimit."
    return 0
  fi
  local kb=$((gb * 1024 * 1024))
  if ulimit -v "$kb" 2>/dev/null; then
    echo "CI virtual memory cap: ${gb} GiB (ulimit -v ${kb} KB; issue #436)."
  else
    echo "CI resource limits: could not set ulimit -v (continuing without cap)."
  fi
}
