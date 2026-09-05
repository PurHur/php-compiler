#!/usr/bin/env bash
# Seed / expand the committed aarch64-linux helper-runtime tier (#36391).
#
# Cross-emits a curated VM_* unit set under PHP_COMPILER_TARGET=aarch64-linux
# (object emit only — no link) and publishes into prelinked/helper-runtime/aarch64-linux/.
# Full corpus refresh still needs a native aarch64 host (or a longer QEMU job).
#
# Usage:
#   ./script/seed-aarch64-helper-runtime.sh
#   ./script/seed-aarch64-helper-runtime.sh --check   # assert seed count + ELF only
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Curated seed: every VM_* unit already published for x86_64-linux. Keep this list
# in sync with `ls prelinked/helper-runtime/x86_64-linux/units/ | grep '^VM_'`.
SEED_UNITS=(
  /VM/AttributeNewInstanceJitHelper.php
  /VM/ClosureBindJitHelper.php
  /VM/CoalesceJitHelper.php
  /VM/EnumCasesJitHelper.php
  /VM/InOperatorJitHelper.php
  /VM/InstanceOfJitHelper.php
  /VM/NullsafeJitHelper.php
  /VM/PropertyIsInitializedJitHelper.php
  /VM/SensitiveParamJitHelper.php
  /VM/SplArrayCastJitHelper.php
  /VM/TryCatchJitHelper.php
  /VM/ValueEchoJitHelper.php
  /VM/VariableFunctionCallJitHelper.php
)

MIN_SEED=${#SEED_UNITS[@]}
ARCH_DIR="$ROOT/prelinked/helper-runtime/aarch64-linux"
UNITS_DIR="$ARCH_DIR/units"

check_seed() {
  local count=0
  local bad=0
  local u slug o
  if [[ ! -d "$UNITS_DIR" ]]; then
    echo "seed-aarch64-helper-runtime: missing $UNITS_DIR" >&2
    exit 1
  fi
  for u in "${SEED_UNITS[@]}"; do
    slug=$(php -r 'require "vendor/autoload.php"; echo PHPCompiler\AOT\HelperRuntimeCache::slugFor($argv[1]);' "$u")
    o="$UNITS_DIR/$slug/unit.o"
    if [[ ! -f "$o" ]]; then
      echo "seed-aarch64-helper-runtime: MISSING $slug" >&2
      bad=$((bad + 1))
      continue
    fi
    machine=$(php -r 'require "vendor/autoload.php"; $m=PHPCompiler\AOT\CompileTarget::readElfMachine($argv[1]); echo $m===null?"null":(string)$m;' "$o")
    if [[ "$machine" != "183" ]]; then
      echo "seed-aarch64-helper-runtime: ELF mismatch $slug e_machine=$machine (want 183)" >&2
      bad=$((bad + 1))
      continue
    fi
    count=$((count + 1))
  done
  echo "seed-aarch64-helper-runtime: check — $count/${MIN_SEED} seed units EM_AARCH64"
  if (( bad > 0 || count < MIN_SEED )); then
    exit 1
  fi
  # Empty result set is not a pass.
  if (( count < 1 )); then
    echo "seed-aarch64-helper-runtime: empty seed — not a pass" >&2
    exit 1
  fi
}

if [[ "${1:-}" == "--check" ]]; then
  check_seed
  exit 0
fi

export PHP_COMPILER_TARGET=aarch64-linux
# Unique cache dir — concurrent helper caches corrupt each other.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$ROOT/build/helper-runtime-cache-aarch64-seed-$$}"
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"

echo "seed-aarch64-helper-runtime: TARGET=$PHP_COMPILER_TARGET cache=$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"
echo "seed-aarch64-helper-runtime: emitting ${#SEED_UNITS[@]} units…"

failed=0
for u in "${SEED_UNITS[@]}"; do
  echo "  emit $u"
  if ! php script/emit-helper-runtime-object.php --unit="$u"; then
    echo "seed-aarch64-helper-runtime: emit FAILED $u" >&2
    failed=$((failed + 1))
  fi
done

if (( failed > 0 )); then
  echo "seed-aarch64-helper-runtime: $failed emit failure(s)" >&2
  exit 1
fi

echo "seed-aarch64-helper-runtime: publishing…"
php script/publish-helper-units-prelink.php "${SEED_UNITS[@]}"

# Refresh README seed count line is left to the caller / PR; assert ELF here.
check_seed
echo "seed-aarch64-helper-runtime: OK"
