#!/usr/bin/env bash
# Apply virtual-memory cap for LLVM compile phases (issue #436).
# Child processes (compile.php, jit.php) inherit the shell ulimit at fork time.
#
# PHP_COMPILER_CI_RAM_GB — virtual-memory cap via ulimit -v (default 8 GiB per CI shell).
# Set to 0 to disable. Raise for LLVM-heavy hosts; do not run multiple full CI containers in parallel.
ci_apply_resource_limits() {
  local gb="${PHP_COMPILER_CI_RAM_GB:-8}"
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
