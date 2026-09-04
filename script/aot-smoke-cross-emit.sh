#!/usr/bin/env bash
#
# Cross-target AOT object-emit smoke (#36391).
#
# On a non-native host we cannot link/run aarch64 binaries (Linker refuses
# PHP_COMPILER_TARGET ≠ host). This gate still proves the AOT smoke corpus
# lowers through TargetMachine for the selected triple and writes ELF objects
# with the expected e_machine — the honest subset of "aot-smoke on arm64"
# that an x86_64 CI box can verify without QEMU.
#
# Native aarch64 hosts: after object emit, also runs full ./script/aot-smoke.sh
# under PHP_COMPILER_TARGET=aarch64-linux (link+run).
#
# Usage:
#   ./script/aot-smoke-cross-emit.sh
#   PHP_COMPILER_TARGET=aarch64-linux ./script/aot-smoke-cross-emit.sh
#   ./script/aot-smoke-cross-emit.sh --target=x86_64-linux   # host-native object check
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    if [ "$#" -eq 0 ]; then
        exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/aot-smoke-cross-emit.sh"
    fi
    args=$(printf '%q ' "$@")
    # shellcheck disable=SC2086
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/aot-smoke-cross-emit.sh ${args}"
fi

TARGET="${PHP_COMPILER_TARGET:-aarch64-linux}"
for arg in "$@"; do
    case "$arg" in
        --target=*) TARGET="${arg#--target=}" ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *)
            echo "aot-smoke-cross-emit: unknown option: $arg" >&2
            exit 2
            ;;
    esac
done

export PHP_COMPILER_TARGET="$TARGET"
: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT
: "${AOT_CROSS_EMIT_TIMEOUT:=180}"

# Same trivial corpus as script/aot-smoke.sh (minus MiniWebApp project build).
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

WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

want_machine="$("$PHP_BIN" -r '
require "vendor/autoload.php";
use PHPCompiler\AOT\CompileTarget;
$t = CompileTarget::resolve(getenv("PHP_COMPILER_TARGET") ?: "aarch64-linux");
$m = $t->elfMachine();
if (null === $m) {
    fwrite(STDERR, "aot-smoke-cross-emit: target has no ELF e_machine\n");
    exit(2);
}
echo $m;
')"

host_id="$("$PHP_BIN" -r '
require "vendor/autoload.php";
echo PHPCompiler\AOT\CompileTarget::hostId();
')"

echo "aot-smoke-cross-emit: target=${TARGET} host=${host_id} want_e_machine=${want_machine}"

pass=0
fail=0
failed_names=()

for entry in "${CASES[@]}"; do
    name="${entry%%@@@*}"
    rest="${entry#*@@@}"
    src="${rest%%@@@*}"
    printf '%s\n' "$src" > "$WORK/$name.php"
    out="$WORK/$name.o"
    printf 'emit  %-9s ' "$name"
    if ! PHP_COMPILER_KEEP_OBJECT_FILE=1 \
        PHP_COMPILER_TARGET="$TARGET" \
        timeout "$AOT_CROSS_EMIT_TIMEOUT" \
        "$PHP_BIN" bin/compile.php -o "$out" "$WORK/$name.php" \
        > "$WORK/$name.compile.log" 2>&1; then
        printf 'FAIL compile (see %s)\n' "$WORK/$name.compile.log"
        tail -8 "$WORK/$name.compile.log" | sed 's/^/        /' || true
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    if [ ! -f "$out" ] || [ ! -s "$out" ]; then
        printf 'FAIL missing object\n'
        fail=$((fail + 1)); failed_names+=("$name"); continue
    fi
    got="$("$PHP_BIN" -r '
require "vendor/autoload.php";
use PHPCompiler\AOT\CompileTarget;
$path = $argv[1];
$want = (int) $argv[2];
$got = CompileTarget::readElfMachine($path);
if (null === $got) {
    fwrite(STDERR, "not ELF\n");
    exit(1);
}
if ($got !== $want) {
    fwrite(STDERR, "e_machine=$got want=$want\n");
    exit(1);
}
echo $got;
' "$out" "$want_machine" 2>"$WORK/$name.elf.err")" || {
        printf 'FAIL ELF %s\n' "$(tr '\n' ' ' < "$WORK/$name.elf.err")"
        fail=$((fail + 1)); failed_names+=("$name"); continue
    }
    printf 'ok    e_machine=%s\n' "$got"
    pass=$((pass + 1))
done

echo
echo "aot-smoke-cross-emit: ${pass} passed, ${fail} failed (object emit)"
if [ "$fail" -ne 0 ]; then
    echo "FAILED: ${failed_names[*]}" >&2
    exit 1
fi
if [ "$pass" -eq 0 ]; then
    echo "aot-smoke-cross-emit: empty result set is not a pass (#36391)" >&2
    exit 1
fi

if [ "$host_id" = "$TARGET" ]; then
    echo "aot-smoke-cross-emit: host matches target — running full aot-smoke (link+run)"
    ./script/aot-smoke.sh
else
    echo "aot-smoke-cross-emit: cross host — link/run deferred (need native ${TARGET} or QEMU)"
fi

echo "aot-smoke-cross-emit: OK"
