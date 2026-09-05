#!/usr/bin/env bash
#
# #36397 slice 6: prove M5 exclusive-write is wired into real hashtable mutate
# paths (not inject-only), and that normal COW separate+write still succeeds.
#
# Usage:
#   ./script/runtime-assert/mutate-assert-smoke.sh
#   make runtime-assert-mutate-smoke
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ ! -f "$ROOT/bin/compile.php" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — repo root misresolved ($ROOT missing bin/compile.php)" >&2
  exit 1
fi

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/runtime-assert/mutate-assert-smoke.sh"
fi

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"

WORKDIR="$(mktemp -d /tmp/phpc-mutate-assert.XXXXXX)"
CACHE="$WORKDIR/helper-cache"
mkdir -p "$CACHE"
trap 'rm -rf "$WORKDIR"' EXIT

IR_DUMP="$WORKDIR/phpc-last.ll"
COW_SRC="$WORKDIR/cow.php"
COW_BIN="$WORKDIR/cow.bin"
cat > "$COW_SRC" <<'PHP'
<?php
$a = [1, 2];
$b = $a;
$a[0] = 9;
echo $a[0], $b[0], "\n";
PHP

echo "runtime-assert-mutate-smoke: compile COW write under ASSERT…"
rm -f /tmp/phpc-last.ll "$IR_DUMP"
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_DUMP_IR=1 \
  "$PHP_BIN" bin/compile.php -o "$COW_BIN" "$COW_SRC"
if [[ ! -f /tmp/phpc-last.ll ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected /tmp/phpc-last.ll from DUMP_IR" >&2
  exit 1
fi
cp /tmp/phpc-last.ll "$IR_DUMP"

if ! grep -q '__hashtable__grow' "$IR_DUMP"; then
  echo "runtime-assert-mutate-smoke: FAIL — IR missing __hashtable__grow" >&2
  exit 1
fi
if ! grep -q '__ref__assert_exclusive' "$IR_DUMP"; then
  echo "runtime-assert-mutate-smoke: FAIL — IR missing __ref__assert_exclusive (mutate path not wired)" >&2
  exit 1
fi
# grow body must call assert_exclusive (not only the inject probe symbol).
if ! grep -A80 'define.*__hashtable__grow' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — __hashtable__grow does not call __ref__assert_exclusive" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW write (must not false-positive M5)…"
out="$("$COW_BIN" 2>&1)"
rc=$?
if [[ "$rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW binary exited $rc: $out" >&2
  exit 1
fi
if [[ "$out" != $'91\n' && "$out" != "91" ]]; then
  # Accept trailing newline variants.
  trimmed="$(printf '%s' "$out" | tr -d '\r')"
  if [[ "$trimmed" != "91" ]]; then
    echo "runtime-assert-mutate-smoke: FAIL — expected 91, got: $out" >&2
    exit 1
  fi
fi

echo "runtime-assert-mutate-smoke: inject shared-write still aborts M5…"
INJ_SRC="$WORKDIR/inject.php"
INJ_BIN="$WORKDIR/inject.bin"
echo '<?php echo "should-not-print\n";' > "$INJ_SRC"
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-inj" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_RUNTIME_ASSERT_INJECT_SHARED_WRITE=1 \
  "$PHP_BIN" bin/compile.php -o "$INJ_BIN" "$INJ_SRC"
set +e
inj_out="$("$INJ_BIN" 2>&1)"
inj_rc=$?
set -e
if [[ "$inj_rc" -eq 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — inject shared-write did not abort" >&2
  exit 1
fi
if ! printf '%s' "$inj_out" | grep -q 'PHPC_RUNTIME_ASSERT M5'; then
  echo "runtime-assert-mutate-smoke: FAIL — missing M5 message: $inj_out" >&2
  exit 1
fi
if printf '%s' "$inj_out" | grep -q 'should-not-print'; then
  echo "runtime-assert-mutate-smoke: FAIL — inject ran past abort" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: OK"
