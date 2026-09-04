#!/usr/bin/env bash
#
# ASan/UBSan link smoke (#36397): compile + run with PHP_COMPILER_ASAN=1.
# Catches the raw-ld "-f may not be used without -shared" regression
# (sanitizer flags must go through clang/gcc) and the #36719 ROOT bug
# (scripts under script/runtime-assert/ need dirname/../..).
#
# Default: echo only (stable link-path proof under Docker --memory=8g).
# Full aot-smoke inline set: RUNTIME_ASSERT_ASAN_FULL=1 (can SIGSEGV under
# 8g ASan shadow pressure — not a cheap-green skip of a known deterministic bug).
#
# Do not wrap ASan binaries in GNU timeout(1). Link suppresses -s strip when
# PHP_COMPILER_ASAN=1 (AotGcSections::stripAtLink).
#
# Usage:
#   ./script/runtime-assert/asan-smoke.sh
#   RUNTIME_ASSERT_ASAN_FULL=1 ./script/runtime-assert/asan-smoke.sh
#   make runtime-assert-asan-smoke
#
set -euo pipefail
# script/runtime-assert → repo root
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$ROOT/bin/compile.php" ]]; then
  echo "runtime-assert-asan-smoke: FAIL — repo root misresolved ($ROOT missing bin/compile.php)" >&2
  exit 1
fi

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/asan-smoke.sh"
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHP_COMPILER_ASAN=1
# Unique cache so concurrent helper-runtime jobs do not collide.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$(mktemp -d /tmp/phpc-asan-cache.XXXXXX)}"
# Leak reports from helper/runtime allocations are out of scope for this smoke.
# quarantine_size_mb keeps RSS workable under Docker --memory=8g (#36397).
export ASAN_OPTIONS="${ASAN_OPTIONS:-detect_leaks=0:halt_on_error=1:abort_on_error=1:quarantine_size_mb=16}"
export UBSAN_OPTIONS="${UBSAN_OPTIONS:-halt_on_error=1}"
: "${AOT_SMOKE_TIMEOUT:=120}"

WORK="$(mktemp -d /tmp/phpc-asan-smoke.XXXXXX)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

# Same inline programs as script/aot-smoke.sh (separator @@@).
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

if [[ "${RUNTIME_ASSERT_ASAN_FULL:-0}" == "1" ]]; then
  CASES=("${ALL_CASES[@]}")
  expect_n=8
  mode="full"
else
  CASES=("${ALL_CASES[0]}")
  expect_n=1
  mode="echo"
fi

pass=0
fail=0
failed_names=()

echo "runtime-assert-asan-smoke: ${#CASES[@]} case(s) mode=${mode} PHP_COMPILER_ASAN=1…"

for entry in "${CASES[@]}"; do
    name="${entry%%@@@*}"
    rest="${entry#*@@@}"
    src="${rest%%@@@*}"
    expected="${rest#*@@@}"

    printf '%s\n' "$src" > "$WORK/$name.php"

    if ! timeout "$AOT_SMOKE_TIMEOUT" "$PHP_BIN" bin/compile.php -o "$WORK/$name.bin" "$WORK/$name.php" \
        > "$WORK/$name.compile.log" 2>&1; then
        printf 'FAIL  %-9s compile/link\n' "$name"
        tail -3 "$WORK/$name.compile.log" | sed 's/^/        /' >&2
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    if [[ ! -x "$WORK/$name.bin" ]]; then
        printf 'FAIL  %-9s missing binary\n' "$name"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    # Do not wrap ASan binaries in GNU timeout(1): it races ASan signal/alt-stack
    # handling and reports spurious "dumped core" on otherwise green runs (#36397).
    set +e
    actual="$("$WORK/$name.bin" 2>&1)"
    run_rc=$?
    set -e
    if [[ "$run_rc" -ne 0 ]]; then
        printf 'FAIL  %-9s run rc=%s: %s\n' "$name" "$run_rc" "${actual:-<no output>}"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    # Command substitution strips one trailing newline.
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL  %-9s expected [%s] got [%s]\n' "$name" "$expected" "$actual"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    printf 'ok    %-9s %s\n' "$name" "$expected"
    pass=$((pass + 1))
done

echo
echo "runtime-assert-asan-smoke: ${pass} passed, ${fail} failed (mode=${mode})"
if [[ "$fail" -ne 0 ]]; then
  echo "FAILED: ${failed_names[*]}" >&2
  exit 1
fi
if [[ "$pass" -ne "$expect_n" ]]; then
  echo "runtime-assert-asan-smoke: FAIL — expected ${expect_n} case(s), got ${pass}" >&2
  exit 1
fi

echo "runtime-assert-asan-smoke: OK (${pass}/${expect_n} ASan-linked; mode=${mode})"
