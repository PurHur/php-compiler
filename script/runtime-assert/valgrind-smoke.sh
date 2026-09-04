#!/usr/bin/env bash
#
# Optional valgrind pass (#36397).
# Skip (exit 0) when valgrind is not installed — the CI image often lacks it.
# When present, fail on any error (valgrind --error-exitcode=1).
#
# Default: echo only (matches asan-smoke). Full set: RUNTIME_ASSERT_VALGRIND_FULL=1.
# Repo root is two levels up from script/runtime-assert/ (#36719 rename).
#
# Usage:
#   ./script/runtime-assert/valgrind-smoke.sh
#   make runtime-assert-valgrind-smoke
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$ROOT/bin/compile.php" ]]; then
  echo "runtime-assert-valgrind-smoke: FAIL — repo root misresolved ($ROOT missing bin/compile.php)" >&2
  exit 1
fi

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/valgrind-smoke.sh"
fi

if ! command -v valgrind >/dev/null 2>&1; then
  echo "runtime-assert-valgrind-smoke: valgrind not installed — skip (exit 0)"
  exit 0
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$(mktemp -d /tmp/phpc-vg-cache.XXXXXX)}"
: "${AOT_SMOKE_TIMEOUT:=120}"

WORK="$(mktemp -d /tmp/phpc-valgrind-smoke.XXXXXX)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

ALL_CASES=(
'echo@@@<?php echo "hi\n";@@@hi'
'arith@@@<?php $a = 6; $b = 7; echo $a * $b, "\n";@@@42'
'concat@@@<?php $s = "ab"; $t = "cd"; echo $s . $t, "|", strlen($s . $t), "\n";@@@abcd|4'
'function@@@<?php function f(int $n): int { return $n + 1; } echo f(41), "\n";@@@42'
'branch@@@<?php $n = 5; if ($n > 3) { echo "big\n"; } else { echo "small\n"; }@@@big'
'loop@@@<?php $t = 0; for ($i = 1; $i <= 10; $i++) { $t += $i; } echo $t, "\n";@@@55'
'array@@@<?php $a = [1, 2, 3]; $t = 0; foreach ($a as $v) { $t += $v; } echo $t, "|", count($a), "\n";@@@6|3'
'class@@@<?php class P { public function __construct(private int $x) {} public function get(): int { return $this->x; } } echo (new P(42))->get(), "\n";@@@42'
)

if [[ "${RUNTIME_ASSERT_VALGRIND_FULL:-0}" == "1" ]]; then
  CASES=("${ALL_CASES[@]}")
else
  CASES=("${ALL_CASES[0]}")
fi

pass=0
fail=0
failed_names=()

echo "runtime-assert-valgrind-smoke: ${#CASES[@]} case(s)…"

for entry in "${CASES[@]}"; do
    name="${entry%%@@@*}"
    rest="${entry#*@@@}"
    src="${rest%%@@@*}"
    expected="${rest#*@@@}"

    printf '%s\n' "$src" > "$WORK/$name.php"
    if ! timeout "$AOT_SMOKE_TIMEOUT" "$PHP_BIN" bin/compile.php -o "$WORK/$name.bin" "$WORK/$name.php" \
        > "$WORK/$name.compile.log" 2>&1; then
        printf 'FAIL  %-9s compile\n' "$name"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    [[ -x "$WORK/$name.bin" ]]

    set +e
    actual="$(valgrind --quiet --error-exitcode=1 --leak-check=no --track-origins=yes \
        "$WORK/$name.bin" 2>&1)"
    run_rc=$?
    set -e
    if [[ "$run_rc" -ne 0 ]]; then
        printf 'FAIL  %-9s valgrind rc=%s\n' "$name" "$run_rc"
        echo "$actual" >&2
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL  %-9s expected [%s] got [%s]\n' "$name" "$expected" "$actual"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    printf 'ok    %-9s %s\n' "$name" "$expected"
    pass=$((pass + 1))
done

echo
echo "runtime-assert-valgrind-smoke: ${pass} passed, ${fail} failed"
if [[ "$fail" -ne 0 ]]; then
  echo "FAILED: ${failed_names[*]}" >&2
  exit 1
fi
echo "runtime-assert-valgrind-smoke: OK (${pass}/${#CASES[@]})"
