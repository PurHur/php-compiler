#!/usr/bin/env bash
#
# Subset hello-world gate (#36204 Done-when).
#
# Builds the same echo program twice:
#   1) full ExtensionRegistry load (default)
#   2) PHP_COMPILER_EXTENSIONS=standard,spl,types,ctype,hash,random
# Asserts both print "hi\n", records byte sizes, and fails if the subset binary
# is *larger* than full (filtering must not regress size upward).
#
# Usage:
#   script/subset-hello-build-gate.sh
#   script/subset-hello-build-gate.sh --json
#   script/subset-hello-build-gate.sh --update-baseline
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    if [ "$#" -eq 0 ]; then
        exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/subset-hello-build-gate.sh"
    fi
    args=$(printf '%q ' "$@")
    # shellcheck disable=SC2086
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/subset-hello-build-gate.sh ${args}"
fi

JSON=0
UPDATE_BASELINE=0
for arg in "$@"; do
  case "$arg" in
    --json) JSON=1 ;;
    --update-baseline) UPDATE_BASELINE=1 ;;
    -h|--help)
      sed -n '2,20p' "$0" | tr -d '#'
      exit 0
      ;;
    *)
      echo "subset-hello-build-gate: unknown argument: ${arg}" >&2
      exit 2
      ;;
  esac
done

: "${PHP_BIN:=php}"
: "${PHP_COMPILER_LLVM_PATH:=/opt/llvm9}"
export PHP_COMPILER_LLVM_PATH
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
: "${PHP_COMPILER_LLVM_MEMORY_LIMIT:=8192M}"
export PHP_COMPILER_LLVM_MEMORY_LIMIT
: "${SUBSET_HELLO_TIMEOUT:=120}"

BASELINE="$REPO_ROOT/test/aot-smoke/SUBSET-HELLO-BASELINE.json"
SUBSET_LIST="${PHP_COMPILER_EXTENSIONS_SUBSET:-standard,spl,types,ctype,hash,random}"

WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

SRC="$WORK/echo.php"
printf '%s\n' '<?php echo "hi\n";' > "$SRC"

build_one() {
  local label="$1"
  local out="$2"
  local log="$3"
  shift 3
  # remaining args are env assignments consumed via env
  local start end elapsed rc
  start=$(date +%s)
  if ! env "$@" timeout "$SUBSET_HELLO_TIMEOUT" "$PHP_BIN" bin/compile.php -o "$out" "$SRC" \
      >"$log" 2>&1; then
    rc=$?
    echo "subset-hello-build-gate: FAIL ${label} compile rc=${rc}" >&2
    tail -20 "$log" >&2
    return "$rc"
  fi
  end=$(date +%s)
  elapsed=$((end - start))
  if [[ ! -x "$out" ]]; then
    echo "subset-hello-build-gate: FAIL ${label} missing binary" >&2
    return 1
  fi
  local got
  # Command substitution strips trailing newlines — compare without the final \n.
  got=$(timeout 20 "$out" || true)
  if [[ "$got" != "hi" ]]; then
    echo "subset-hello-build-gate: FAIL ${label} output $(printf %q "$got")" >&2
    return 1
  fi
  local bytes
  bytes=$(wc -c <"$out" | tr -d ' ')
  printf '%s %s %s\n' "$label" "$bytes" "$elapsed"
}

echo "subset-hello-build-gate: full registry…"
FULL_LINE=$(build_one full "$WORK/full.bin" "$WORK/full.log")
echo "subset-hello-build-gate: subset ${SUBSET_LIST}…"
# Clear any ambient filter, then set only the subset.
unset PHP_COMPILER_EXTENSIONS || true
SUB_LINE=$(build_one subset "$WORK/subset.bin" "$WORK/subset.log" \
  "PHP_COMPILER_EXTENSIONS=${SUBSET_LIST}")

FULL_BYTES=$(echo "$FULL_LINE" | awk '{print $2}')
FULL_SEC=$(echo "$FULL_LINE" | awk '{print $3}')
SUB_BYTES=$(echo "$SUB_LINE" | awk '{print $2}')
SUB_SEC=$(echo "$SUB_LINE" | awk '{print $3}')

if [[ "$SUB_BYTES" -gt "$FULL_BYTES" ]]; then
  echo "subset-hello-build-gate: FAIL subset (${SUB_BYTES}) larger than full (${FULL_BYTES})" >&2
  exit 1
fi

PAYLOAD=$(cat <<EOF
{
  "issue": 36204,
  "subset": "${SUBSET_LIST}",
  "full_bytes": ${FULL_BYTES},
  "subset_bytes": ${SUB_BYTES},
  "full_compile_s": ${FULL_SEC},
  "subset_compile_s": ${SUB_SEC},
  "delta_bytes": $((FULL_BYTES - SUB_BYTES))
}
EOF
)

if [[ "$UPDATE_BASELINE" -eq 1 ]]; then
  printf '%s\n' "$PAYLOAD" > "$BASELINE"
  echo "subset-hello-build-gate: wrote ${BASELINE}"
fi

if [[ "$JSON" -eq 1 ]]; then
  printf '%s\n' "$PAYLOAD"
else
  echo "subset-hello-build-gate: OK full=${FULL_BYTES}B/${FULL_SEC}s subset=${SUB_BYTES}B/${SUB_SEC}s delta=$((FULL_BYTES - SUB_BYTES))B"
fi

if [[ -f "$BASELINE" && "$UPDATE_BASELINE" -eq 0 ]]; then
  # Soft check: subset must not grow vs blessed baseline (full may drift with helper cache).
  BASE_SUB=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (int)$j["subset_bytes"];' "$BASELINE")
  if [[ "$SUB_BYTES" -gt $((BASE_SUB + 65536)) ]]; then
    echo "subset-hello-build-gate: FAIL subset grew >64KiB vs baseline ${BASE_SUB} → ${SUB_BYTES}" >&2
    echo "  re-bless: script/subset-hello-build-gate.sh --update-baseline" >&2
    exit 1
  fi
fi

exit 0
