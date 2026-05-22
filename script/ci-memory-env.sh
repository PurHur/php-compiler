#!/usr/bin/env bash
# Per-process PHP memory_limit for CI and spawned bin/vm.php children (via PHP_COMPILER_MEMORY_LIMIT).
#
# PHP_COMPILER_MEMORY_LIMIT — PHPUnit + scripts (default 1536M).
# PHP_COMPILER_LLVM_MEMORY_LIMIT — used during @group llvm phases (default 4096M).
# Export PHP_COMPILER_MEMORY_LIMIT=-1 only for local debugging (not in CI).

php_compiler_apply_memory_php_opt() {
  local limit="${PHP_COMPILER_MEMORY_LIMIT:-1536M}"
  export PHP_COMPILER_MEMORY_LIMIT="$limit"
  PHP_OPTS+=(-d "memory_limit=${limit}")
}

ci_apply_default_memory_env() {
  export PHP_COMPILER_MEMORY_LIMIT="${PHP_COMPILER_MEMORY_LIMIT:-1536M}"
  php_compiler_apply_memory_php_opt
}

ci_apply_llvm_memory_env() {
  export PHP_COMPILER_MEMORY_LIMIT="${PHP_COMPILER_LLVM_MEMORY_LIMIT:-4096M}"
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
