#!/usr/bin/env bash
# AOT build smoke for the fast gate (#16010 follow-up).
#
# The fast gate historically never invoked LLVM, so commits that broke every
# `phpc build` (invalid IR, silent-exit binaries) merged through green gates
# and survived for hours (#15632, #15642, #16010). This check compiles and
# RUNS a real binary and compares its output against the VM whenever LLVM 9
# is available. Skips cleanly (exit 0) when no toolchain is present.
#
# Tier 1 (enforced): hello-class script — build, execute, VM-differential.
# Tier 2 (report-only until #15642 closes): known-broken builtin constructs —
#   failures are printed with issue refs but do not fail the gate yet.
#
# Wall target: < 15 s with LLVM, < 1 s without.
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

if [[ "${AOT_BUILD_SMOKE_GATE:-1}" != "1" ]]; then
  echo "check-aot-build-smoke: SKIP (AOT_BUILD_SMOKE_GATE=0)"
  exit 0
fi

LLVM_DIR=""
for dir in "${PHP_COMPILER_LLVM_PATH:-}" "$ROOT/.llvm" /opt/llvm9; do
  if [[ -n "$dir" && -f "$dir/libLLVM-9.so.1" ]]; then
    LLVM_DIR="$dir"
    break
  fi
done
if [[ -z "$LLVM_DIR" ]]; then
  echo "check-aot-build-smoke: SKIP (LLVM 9 not available — Docker/ci-local covers this)"
  exit 0
fi
export PHP_COMPILER_LLVM_PATH="$LLVM_DIR"
export LD_LIBRARY_PATH="$LLVM_DIR${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

PHP_BIN="${PHP_BIN:-php}"
WORK="$(mktemp -d /tmp/phpc-aot-smoke.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

fail=0

# Tier 1 — enforced: build + execute + VM differential on a script that uses
# variables (not constant-foldable — the #15632 silent-exit class).
cat > "$WORK/t1.php" <<'PHP'
<?php
$greeting = "Hello";
$who = "AOT";
echo $greeting, " ", $who, "!\n";
echo strlen($who), "\n";
PHP

vm_out="$("$PHP_BIN" bin/vm.php "$WORK/t1.php" 2>/dev/null)"
if [[ -z "$vm_out" ]]; then
  echo "check-aot-build-smoke: FAILED — VM produced no output for tier-1 script" >&2
  exit 1
fi

if ! ./phpc build -o "$WORK/t1" "$WORK/t1.php" >"$WORK/build.log" 2>&1; then
  echo "check-aot-build-smoke: FAILED — phpc build exited non-zero (tier 1)" >&2
  tail -5 "$WORK/build.log" >&2
  exit 1
fi
aot_out="$("$WORK/t1" 2>/dev/null)"
aot_rc=$?
if [[ $aot_rc -ne 0 ]]; then
  echo "check-aot-build-smoke: FAILED — tier-1 binary exit ${aot_rc}" >&2
  fail=1
elif [[ "$aot_out" != "$vm_out" ]]; then
  echo "check-aot-build-smoke: FAILED — AOT/VM output differ (silent-exit class, #15632):" >&2
  echo "  VM : $(echo "$vm_out" | tr '\n' '|')" >&2
  echo "  AOT: $(echo "$aot_out" | tr '\n' '|')" >&2
  fail=1
else
  echo "check-aot-build-smoke: OK tier 1 (build + execute + VM parity)"
fi

# Tier 2 — report-only until #15642 closes: constructs known to break the
# compile or diverge. Kept visible so progress/regression is observable.
declare -A T2=(
  [explode_crlf]='$p = explode("\r\n", "a\r\nb\r\nc"); echo count($p), "\n";'
  [preg_capture]='preg_match("/b(oundary)=(\\w+)/", "boundary=x", $m); echo $m[2] ?? "(none)", "\n";'
  [str_contains]='echo str_contains("x: y", ":") ? "y" : "n", "\n";'
  [substr_neg]='echo substr("hello", 0, -2), "\n";'
  [strlen_concat]='$a = "ab"; $b = "cdef"; echo strlen($a . $b), "\n";'
)
t2_broken=0
for name in "${!T2[@]}"; do
  printf '<?php\n%s\n' "${T2[$name]}" > "$WORK/$name.php"
  expected="$("$PHP_BIN" bin/vm.php "$WORK/$name.php" 2>/dev/null)"
  if ./phpc build -o "$WORK/$name" "$WORK/$name.php" >/dev/null 2>&1 \
    && [[ "$("$WORK/$name" 2>/dev/null)" == "$expected" ]]; then
    :
  else
    echo "check-aot-build-smoke: tier-2 still broken: $name (#15642 — report-only)"
    ((t2_broken++)) || true
  fi
done
if [[ $t2_broken -eq 0 ]]; then
  echo "check-aot-build-smoke: tier 2 fully green — consider enforcing (#15642 closed?)"
fi

exit $fail
