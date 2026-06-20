#!/usr/bin/env bash
# Per-process PHP memory_limit for CI and spawned bin/vm.php children (via PHP_COMPILER_MEMORY_LIMIT).
# Defaults live in script/ci-defaults.env. Unlimited memory_limit=-1 is blocked repo-wide.

_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=ci-defaults.env
source "$_SCRIPT_DIR/ci-defaults.env"

php_compiler_reject_unlimited_memory() {
  if [[ "${PHP_COMPILER_MEMORY_LIMIT}" == "-1" || "${PHP_COMPILER_LLVM_MEMORY_LIMIT}" == "-1" ]]; then
    echo "CI: PHP_COMPILER_MEMORY_LIMIT/LLVM_MEMORY_LIMIT=-1 is not allowed (issue #497)." >&2
    exit 1
  fi
}

php_compiler_apply_memory_php_opt() {
  php_compiler_reject_unlimited_memory
  PHP_OPTS+=(-d "memory_limit=${PHP_COMPILER_MEMORY_LIMIT}")
}

ci_apply_default_memory_env() {
  php_compiler_apply_memory_php_opt
}

# php-cfg/php-types vendor patches (union types, etc.) are not in composer packages;
# bootstrap/AOT gates must apply them before compile.php (issue #2499).
ci_ensure_vendor_patches() {
  local root
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
  if [[ -x "${root}/script/apply-patches.sh" && -d "${root}/vendor/ircmaxell/php-cfg" ]]; then
    # Fast path: grep-only marker check (~ms). Full apply-patches is ~17s even when already applied.
    if "${root}/script/apply-patches.sh" --verify-only >/dev/null 2>&1; then
      return 0
    fi
    # Redirect stderr of the *invoking shell* too, so a crashing subprocess doesn't spam CI logs.
    { "${root}/script/apply-patches.sh" >/dev/null; } 2>/dev/null || true
  fi
}

ci_apply_llvm_memory_env() {
  ci_ensure_vendor_patches
  export PHP_COMPILER_MEMORY_LIMIT="${PHP_COMPILER_LLVM_MEMORY_LIMIT}"
  PHP_OPTS+=(-d "memory_limit=${PHP_COMPILER_MEMORY_LIMIT}")
}

ci_guard_parallel_ci() {
  if ! command -v docker >/dev/null 2>&1; then
    return 0
  fi
  local running
  running="$(docker ps -q --filter ancestor=php-compiler:22.04-dev 2>/dev/null | wc -l | tr -d ' ')"
  if [[ "${running:-0}" -gt 0 ]] && [[ "${PHP_COMPILER_ALLOW_PARALLEL_CI:-}" != "1" ]]; then
    echo "WARNING: ${running} php-compiler:22.04-dev container(s) already running."
    echo "  Parallel full CI runs can use 40+ GiB RAM each. Stop extras or set PHP_COMPILER_ALLOW_PARALLEL_CI=1."
  fi
}
