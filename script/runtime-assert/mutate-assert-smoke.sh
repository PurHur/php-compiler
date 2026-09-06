#!/usr/bin/env bash
#
# #36397 slice 6–13: prove M5 exclusive-write is wired into real hashtable mutate
# paths (grow + string-key + object-key + unset + by-ref stdlib mutators incl. shuffle
# + next/prev/reset/end), and that normal COW separate+write/unset/push/sort/walk/
# multisort/shuffle/pointer still succeeds.
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
# Object-key setters were a separate hole (no grow / string-key chokepoint) — #36397 slice 7.
if ! grep -q '__hashtable__setObjectKeyLong' "$IR_DUMP"; then
  echo "runtime-assert-mutate-smoke: FAIL — IR missing __hashtable__setObjectKeyLong" >&2
  exit 1
fi
if ! grep -A40 'define.*__hashtable__setObjectKeyLong' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — setObjectKeyLong does not call __ref__assert_exclusive" >&2
  exit 1
fi
if ! grep -A40 'define.*__hashtable__setObjectKeyObject' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — setObjectKeyObject does not call __ref__assert_exclusive" >&2
  exit 1
fi
# Unset paths mutate without grow / string-key / object-key write chokepoints — #36397 slice 8.
if ! grep -q '__hashtable__unsetLongAt' "$IR_DUMP"; then
  echo "runtime-assert-mutate-smoke: FAIL — IR missing __hashtable__unsetLongAt" >&2
  exit 1
fi
if ! grep -A40 'define.*__hashtable__unsetLongAt' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — unsetLongAt does not call __ref__assert_exclusive" >&2
  exit 1
fi
if ! grep -A40 'define.*__hashtable__unsetStringKey' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — unsetStringKey does not call __ref__assert_exclusive" >&2
  exit 1
fi
if ! grep -A40 'define.*__hashtable__unsetObjectKey' "$IR_DUMP" | grep -q '__ref__assert_exclusive'; then
  echo "runtime-assert-mutate-smoke: FAIL — unsetObjectKey does not call __ref__assert_exclusive" >&2
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

echo "runtime-assert-mutate-smoke: run COW unset (must not false-positive M5)…"
UNSET_SRC="$WORKDIR/cow_unset.php"
UNSET_BIN="$WORKDIR/cow_unset.bin"
cat > "$UNSET_SRC" <<'PHP'
<?php
$a = [1, 2];
$b = $a;
unset($a[0]);
echo isset($a[0]) ? '1' : '0';
echo isset($b[0]) ? '1' : '0';
echo "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-unset" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  "$PHP_BIN" bin/compile.php -o "$UNSET_BIN" "$UNSET_SRC"
unset_out="$("$UNSET_BIN" 2>&1)"
unset_rc=$?
if [[ "$unset_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW unset exited $unset_rc: $unset_out" >&2
  exit 1
fi
unset_trimmed="$(printf '%s' "$unset_out" | tr -d '\r')"
if [[ "$unset_trimmed" != "01" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 01 from COW unset, got: $unset_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW array_push (must not false-positive M5)…"
PUSH_SRC="$WORKDIR/cow_push.php"
PUSH_BIN="$WORKDIR/cow_push.bin"
cat > "$PUSH_SRC" <<'PHP'
<?php
$a = [1, 2];
$b = $a;
array_push($a, 3);
echo count($a), count($b), isset($b[2]) ? '1' : '0', "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-push" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  "$PHP_BIN" bin/compile.php -o "$PUSH_BIN" "$PUSH_SRC"
push_out="$("$PUSH_BIN" 2>&1)"
push_rc=$?
if [[ "$push_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW array_push exited $push_rc: $push_out" >&2
  exit 1
fi
push_trimmed="$(printf '%s' "$push_out" | tr -d '\r')"
if [[ "$push_trimmed" != "320" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 320 from COW array_push, got: $push_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW sort (must not false-positive M5)…"
SORT_SRC="$WORKDIR/cow_sort.php"
SORT_BIN="$WORKDIR/cow_sort.bin"
cat > "$SORT_SRC" <<'PHP'
<?php
$a = [3, 1, 2];
$b = $a;
sort($a);
echo implode(',', $a), '|', implode(',', $b), "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-sort" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_DUMP_IR=1 \
  "$PHP_BIN" bin/compile.php -o "$SORT_BIN" "$SORT_SRC"
if [[ -f /tmp/phpc-last.ll ]]; then
  if ! grep -A40 'define.*__hashtable__sortPacked' /tmp/phpc-last.ll | grep -q '__ref__assert_exclusive'; then
    echo "runtime-assert-mutate-smoke: FAIL — __hashtable__sortPacked does not call __ref__assert_exclusive" >&2
    exit 1
  fi
fi
sort_out="$("$SORT_BIN" 2>&1)"
sort_rc=$?
if [[ "$sort_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW sort exited $sort_rc: $sort_out" >&2
  exit 1
fi
sort_trimmed="$(printf '%s' "$sort_out" | tr -d '\r')"
if [[ "$sort_trimmed" != "1,2,3|3,1,2" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 1,2,3|3,1,2 from COW sort, got: $sort_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW array_walk (must not false-positive M5)…"
WALK_SRC="$WORKDIR/cow_walk.php"
WALK_BIN="$WORKDIR/cow_walk.bin"
cat > "$WALK_SRC" <<'PHP'
<?php
$a = [1, 2];
$b = $a;
array_walk($a, function (&$v) { $v *= 10; });
echo implode(',', $a), '|', implode(',', $b), "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-walk" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  "$PHP_BIN" bin/compile.php -o "$WALK_BIN" "$WALK_SRC"
walk_out="$("$WALK_BIN" 2>&1)"
walk_rc=$?
if [[ "$walk_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW array_walk exited $walk_rc: $walk_out" >&2
  exit 1
fi
walk_trimmed="$(printf '%s' "$walk_out" | tr -d '\r')"
if [[ "$walk_trimmed" != "10,20|1,2" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 10,20|1,2 from COW array_walk, got: $walk_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW array_multisort (must not false-positive M5)…"
MSORT_SRC="$WORKDIR/cow_multisort.php"
MSORT_BIN="$WORKDIR/cow_multisort.bin"
cat > "$MSORT_SRC" <<'PHP'
<?php
$a = [3, 1, 2];
$b = $a;
$c = ['c', 'a', 'b'];
array_multisort($a, $c);
echo implode(',', $a), '|', implode(',', $b), '|', implode(',', $c), "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-msort" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_DUMP_IR=1 \
  "$PHP_BIN" bin/compile.php -o "$MSORT_BIN" "$MSORT_SRC"
# M5 is at the call site (before packVariables addrefs); IR must still name assert_exclusive.
if [[ -f /tmp/phpc-last.ll ]]; then
  if ! grep -q '__ref__assert_exclusive' /tmp/phpc-last.ll; then
    echo "runtime-assert-mutate-smoke: FAIL — COW array_multisort IR missing __ref__assert_exclusive" >&2
    exit 1
  fi
fi
msort_out="$("$MSORT_BIN" 2>&1)"
msort_rc=$?
if [[ "$msort_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW array_multisort exited $msort_rc: $msort_out" >&2
  exit 1
fi
msort_trimmed="$(printf '%s' "$msort_out" | tr -d '\r')"
if [[ "$msort_trimmed" != "1,2,3|3,1,2|a,b,c" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 1,2,3|3,1,2|a,b,c from COW array_multisort, got: $msort_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW shuffle (must not false-positive M5)…"
SHUF_SRC="$WORKDIR/cow_shuffle.php"
SHUF_BIN="$WORKDIR/cow_shuffle.bin"
cat > "$SHUF_SRC" <<'PHP'
<?php
$a = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$b = $a;
shuffle($a);
$sorted = $a;
sort($sorted);
echo implode(',', $sorted), '|', implode(',', $b), "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-shuffle" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_DUMP_IR=1 \
  "$PHP_BIN" bin/compile.php -o "$SHUF_BIN" "$SHUF_SRC"
if [[ -f /tmp/phpc-last.ll ]]; then
  if ! grep -q '__ref__assert_exclusive' /tmp/phpc-last.ll; then
    echo "runtime-assert-mutate-smoke: FAIL — COW shuffle IR missing __ref__assert_exclusive" >&2
    exit 1
  fi
  if ! grep -q '__compiler_random_bytes' /tmp/phpc-last.ll; then
    echo "runtime-assert-mutate-smoke: FAIL — COW shuffle IR missing __compiler_random_bytes (Fisher–Yates)" >&2
    exit 1
  fi
fi
shuf_out="$("$SHUF_BIN" 2>&1)"
shuf_rc=$?
if [[ "$shuf_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW shuffle exited $shuf_rc: $shuf_out" >&2
  exit 1
fi
shuf_trimmed="$(printf '%s' "$shuf_out" | tr -d '\r')"
if [[ "$shuf_trimmed" != "1,2,3,4,5,6,7,8,9,10|1,2,3,4,5,6,7,8,9,10" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected sorted|original from COW shuffle, got: $shuf_out" >&2
  exit 1
fi
# Shuffle must actually permute (not NestedJIT no-op) — run without relying on $b.
SHUF2_SRC="$WORKDIR/shuffle_perm.php"
SHUF2_BIN="$WORKDIR/shuffle_perm.bin"
cat > "$SHUF2_SRC" <<'PHP'
<?php
$a = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$before = implode(',', $a);
$changed = false;
for ($i = 0; $i < 32; $i++) {
    $t = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    shuffle($t);
    if (implode(',', $t) !== $before) {
        $changed = true;
        break;
    }
}
echo $changed ? "perm\n" : "noop\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-shuffle-perm" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  "$PHP_BIN" bin/compile.php -o "$SHUF2_BIN" "$SHUF2_SRC"
shuf2_out="$("$SHUF2_BIN" 2>&1)"
shuf2_rc=$?
if [[ "$shuf2_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — shuffle perm probe exited $shuf2_rc: $shuf2_out" >&2
  exit 1
fi
shuf2_trimmed="$(printf '%s' "$shuf2_out" | tr -d '\r')"
if [[ "$shuf2_trimmed" != "perm" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — shuffle did not permute in 32 tries (NestedJIT no-op?): $shuf2_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW next/end (must not false-positive M5)…"
PTR_SRC="$WORKDIR/cow_pointer.php"
PTR_BIN="$WORKDIR/cow_pointer.bin"
cat > "$PTR_SRC" <<'PHP'
<?php
$a = [10, 20, 30];
$b = $a;
next($a);
end($a);
echo var_export(current($a), true), '|', var_export(current($b), true), '|';
echo var_export(key($a), true), '|', var_export(key($b), true), "\n";
PHP
env \
  PHP_COMPILER_HELPER_RUNTIME_O=0 \
  PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="$CACHE-pointer" \
  PHP_COMPILER_RUNTIME_ASSERT=1 \
  PHP_COMPILER_DUMP_IR=1 \
  "$PHP_BIN" bin/compile.php -o "$PTR_BIN" "$PTR_SRC"
if [[ -f /tmp/phpc-last.ll ]]; then
  if ! grep -q '__ref__assert_exclusive' /tmp/phpc-last.ll; then
    echo "runtime-assert-mutate-smoke: FAIL — COW next/end IR missing __ref__assert_exclusive" >&2
    exit 1
  fi
fi
ptr_out="$("$PTR_BIN" 2>&1)"
ptr_rc=$?
if [[ "$ptr_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW next/end exited $ptr_rc: $ptr_out" >&2
  exit 1
fi
ptr_trimmed="$(printf '%s' "$ptr_out" | tr -d '\r')"
if [[ "$ptr_trimmed" != "30|10|2|0" ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 30|10|2|0 from COW next/end, got: $ptr_out" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run COW foreach-by-ref (must not false-positive M5)…"
cat > "$WORKDIR/foreach_cow.php" <<'PHP'
<?php
$b = [1, 2];
$a = $b;
foreach ($a as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', $b), '|', implode(',', $a), "\n";
PHP
"$PHP_BIN" bin/compile.php -o "$WORKDIR/foreach_cow.bin" "$WORKDIR/foreach_cow.php" >/dev/null
fe_out="$("$WORKDIR/foreach_cow.bin" 2>&1)"
fe_rc=$?
if [[ "$fe_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — COW foreach-by-ref exited $fe_rc: $fe_out" >&2
  exit 1
fi
if [[ "$fe_out" != $'1,2|10,20\n' && "$fe_out" != '1,2|10,20' ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 1,2|10,20 from COW foreach-by-ref, got: $fe_out" >&2
  exit 1
fi
if ! grep -q 'separateContainerForWrite' "$ROOT/lib/VM/VmIteratorForeach.php"; then
  echo "runtime-assert-mutate-smoke: FAIL — VmIteratorForeach.php missing separateContainerForWrite" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: run string-key foreach-by-ref RMW (no dim-hydrate)…"
cat > "$WORKDIR/foreach_rmw.php" <<'PHP'
<?php
$b = ['x' => 1, 'y' => 2];
$a = $b;
foreach ($a as &$w) {
    $w += 1;
}
unset($w);
echo implode(',', $a), '|', implode(',', $b), "\n";
$b2 = ['x' => 1, 'y' => 2];
$a2 = $b2;
foreach ($a2 as &$w2) {
    $w2 = $w2 + 1;
}
unset($w2);
echo implode(',', $a2), '|', implode(',', $b2), "\n";
PHP
"$PHP_BIN" bin/compile.php -o "$WORKDIR/foreach_rmw.bin" "$WORKDIR/foreach_rmw.php" >/dev/null
rmw_out="$("$WORKDIR/foreach_rmw.bin" 2>&1)"
rmw_rc=$?
if [[ "$rmw_rc" -ne 0 ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — string-key foreach RMW exited $rmw_rc: $rmw_out" >&2
  exit 1
fi
if [[ "$rmw_out" != $'2,3|1,2\n2,3|1,2\n' && "$rmw_out" != $'2,3|1,2\n2,3|1,2' ]]; then
  echo "runtime-assert-mutate-smoke: FAIL — expected 2,3|1,2 twice from string-key foreach RMW, got: $rmw_out" >&2
  exit 1
fi
if ! grep -q 'foreachByRefPackedArm' "$ROOT/lib/JIT/HashTableWriteLlvm.php"; then
  echo "runtime-assert-mutate-smoke: FAIL — HashTableWriteLlvm.php missing foreachByRefPackedArm hydrate skip" >&2
  exit 1
fi

echo "runtime-assert-mutate-smoke: source gates for by-ref mutator COW (#36397 slice 9–16)…"
for f in \
  lib/JIT/Builtin/ArrayPushRuntime.php \
  lib/JIT/Builtin/ArrayUnshiftRuntime.php \
  lib/JIT/Builtin/ArrayPopRuntime.php \
  lib/JIT/Builtin/ArrayShiftRuntime.php \
  lib/JIT/Builtin/ArraySpliceRuntime.php \
  lib/JIT/Builtin/SortRuntime.php \
  lib/JIT/Builtin/ArrayWalkRuntime.php \
  lib/JIT/Builtin/UsortRuntime.php \
  lib/JIT/Builtin/ValueSortRuntime.php \
  lib/JIT/Builtin/KeySortRuntime.php \
  lib/JIT/Builtin/NaturalSortRuntime.php \
  lib/JIT/Builtin/MultisortRuntime.php \
  lib/JIT/Builtin/ShuffleRuntime.php \
  lib/JIT/Builtin/ArrayPointerRuntime.php \
  lib/VM/VmIteratorForeach.php
do
  if ! grep -q 'separateContainerForWrite' "$ROOT/$f"; then
    echo "runtime-assert-mutate-smoke: FAIL — $f missing separateContainerForWrite" >&2
    exit 1
  fi
done
if ! grep -q 'emitAssertExclusiveCall' "$ROOT/lib/JIT/Builtin/MultisortRuntime.php"; then
  echo "runtime-assert-mutate-smoke: FAIL — MultisortRuntime.php missing emitAssertExclusiveCall" >&2
  exit 1
fi
if ! grep -q 'emitAssertExclusiveCall' "$ROOT/lib/JIT/Builtin/ArrayPointerRuntime.php"; then
  echo "runtime-assert-mutate-smoke: FAIL — ArrayPointerRuntime.php missing emitAssertExclusiveCall" >&2
  exit 1
fi
for f in \
  lib/JIT/HashTablePopLastLlvm.php \
  lib/JIT/HashTableShiftLlvm.php \
  lib/JIT/HashTableSpliceLlvm.php
do
  if ! grep -q 'emitAssertExclusiveCall' "$ROOT/$f"; then
    echo "runtime-assert-mutate-smoke: FAIL — $f missing emitAssertExclusiveCall" >&2
    exit 1
  fi
done
if ! grep -q 'emitAssertExclusiveCall' "$ROOT/lib/JIT/Builtin/Type/HashTable.php"; then
  echo "runtime-assert-mutate-smoke: FAIL — HashTable.php missing emitAssertExclusiveCall (sortPacked)" >&2
  exit 1
fi
if ! grep -A5 'implementSortPacked' "$ROOT/lib/JIT/Builtin/Type/HashTable.php" | head -1 >/dev/null; then
  :
fi
# Packed sort ABI must emit M5 at entry (#36397 slice 10).
# Avoid `grep -q` under `pipefail`: early close SIGPIPEs awk → false FAIL.
if ! awk '/function implementSortPacked\(/,/^    private function implementSortPackedNatural/' \
    "$ROOT/lib/JIT/Builtin/Type/HashTable.php" | grep 'emitAssertExclusiveCall' >/dev/null; then
  echo "runtime-assert-mutate-smoke: FAIL — implementSortPacked missing emitAssertExclusiveCall" >&2
  exit 1
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
