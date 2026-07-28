#!/usr/bin/env bash
#
# AOT smoke test — does the compiler still produce a working binary at all?
#
# This is deliberately the cheapest possible correctness gate. It compiles a handful of tiny
# programs with bin/compile.php and checks their output byte-for-byte. It is NOT a feature test:
# every program here is boring on purpose, so a failure means the toolchain is broken rather than
# that some language corner is unsupported.
#
# Why it exists (#24194, #24230): on 2026-07-28 three separate commits shipped to master that made
# EVERY AOT binary fail — twice a startup segfault, once an unlinked symbol. Each time the
# differential corpus read as a mass feature regression, because every case fails when the toolchain
# is broken. This distinguishes "the compiler is broken" from "a feature is broken" in seconds,
# which is the difference between a five-minute revert and a day of chasing phantom regressions.
#
# Run it before believing any sweep result. Usage:
#   script/aot-smoke.sh            # compile+run each case, diff against expected
#   script/aot-smoke.sh --keep     # keep the built binaries for inspection
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

WORK="$(mktemp -d)"
cleanup() { [ "$KEEP" -eq 1 ] || rm -rf "$WORK"; }
trap cleanup EXIT

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT
: "${AOT_SMOKE_TIMEOUT:=120}"
: "${AOT_SMOKE_RUN_TIMEOUT:=20}"

# name @@@ source @@@ expected-stdout
# Keep each program trivial and its output unambiguous. The separator is @@@ rather than a single
# punctuation char because the sources themselves contain '|' and ':'.
CASES=(
'echo@@@<?php echo "hi\n";@@@hi'
'arith@@@<?php $a = 6; $b = 7; echo $a * $b, "\n";@@@42'
'concat@@@<?php $s = "ab"; $t = "cd"; echo $s . $t, "|", strlen($s . $t), "\n";@@@abcd|4'
'function@@@<?php function f(int $n): int { return $n + 1; } echo f(41), "\n";@@@42'
'branch@@@<?php $n = 5; if ($n > 3) { echo "big\n"; } else { echo "small\n"; }@@@big'
'loop@@@<?php $t = 0; for ($i = 1; $i <= 10; $i++) { $t += $i; } echo $t, "\n";@@@55'
'array@@@<?php $a = [1, 2, 3]; $t = 0; foreach ($a as $v) { $t += $v; } echo $t, "|", count($a), "\n";@@@6|3'
'class@@@<?php class P { public function __construct(private int $x) {} public function get(): int { return $this->x; } } echo (new P(42))->get(), "\n";@@@42'
)

pass=0
fail=0
failed_names=()

for entry in "${CASES[@]}"; do
    name="${entry%%@@@*}"
    rest="${entry#*@@@}"
    src="${rest%%@@@*}"
    expected="${rest#*@@@}"

    printf '%s\n' "$src" > "$WORK/$name.php"

    timeout "$AOT_SMOKE_TIMEOUT" "$PHP_BIN" bin/compile.php -o "$WORK/$name.bin" "$WORK/$name.php" \
        > "$WORK/$name.compile.log" 2>&1
    compile_rc=$?
    if [ "$compile_rc" -ne 0 ]; then
        printf 'FAIL  %-9s compile failed (rc=%s)\n' "$name" "$compile_rc"
        tail -2 "$WORK/$name.compile.log" | sed 's/^/        /'
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    if [ ! -x "$WORK/$name.bin" ]; then
        printf 'FAIL  %-9s compile reported success but emitted no binary\n' "$name"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    actual="$(timeout "$AOT_SMOKE_RUN_TIMEOUT" "$WORK/$name.bin" 2>&1)"
    rc=$?
    if [ "$rc" -ne 0 ]; then
        printf 'FAIL  %-9s binary exited %s: %s\n' "$name" "$rc" "${actual:-<no output>}"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    if [ "$actual" != "$expected" ]; then
        printf 'FAIL  %-9s expected %-12s got %s\n' "$name" "[$expected]" "[$actual]"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi

    printf 'ok    %-9s %s\n' "$name" "$expected"
    pass=$((pass + 1))
done

echo
echo "aot-smoke: ${pass} passed, ${fail} failed"
if [ "$fail" -ne 0 ]; then
    echo "FAILED: ${failed_names[*]}" >&2
    echo "The AOT toolchain is broken — a differential sweep run now will report mass failure that" >&2
    echo "has nothing to do with the cases it is testing. Fix or revert before measuring anything." >&2
    exit 1
fi
exit 0
