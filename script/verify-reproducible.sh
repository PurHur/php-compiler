#!/usr/bin/env bash
#
# Verify AOT binaries are bit-identical across two isolated builds (#36399).
#
# Builds a tiny hello program twice with different isolation roots (HOME +
# TMPDIR + outdir) — approximating two CI runners on one host — and compares
# sha256 of the executables plus the GNU build-id note bytes.
#
# Usage:
#   script/verify-reproducible.sh
#   script/verify-reproducible.sh --with-miniwebapp   # also build examples/003-MiniWebApp (slow)
#   script/verify-reproducible.sh --json              # machine-readable summary on stdout
#
# On RunForge / hosts without image LLVM, re-execs via docker-exec.sh.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if ! { [[ -f /.dockerenv ]] && [[ -f /opt/llvm9/libLLVM-9.so.1 ]]; } \
    && [[ "${PHP_COMPILER_IN_DOCKER:-0}" != "1" ]]; then
    if [ "$#" -eq 0 ]; then
        exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/verify-reproducible.sh"
    fi
    args=$(printf '%q ' "$@")
    # shellcheck disable=SC2086
    exec ./script/docker-exec.sh -- bash -lc "source script/php-env.sh && ./script/verify-reproducible.sh ${args}"
fi

WITH_MINI=0
JSON=0
for arg in "$@"; do
  case "$arg" in
    --with-miniwebapp) WITH_MINI=1 ;;
    --json) JSON=1 ;;
    -h|--help)
      sed -n '2,16p' "$0"
      exit 0
      ;;
  esac
done

# shellcheck source=script/php-env.sh
source "$REPO_ROOT/script/php-env.sh"

export SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-1700000000}"
export PHP_COMPILER_REPRODUCIBLE="${PHP_COMPILER_REPRODUCIBLE:-1}"

WORKDIR=$(mktemp -d /tmp/phpc-repro.XXXXXX)
cleanup() { rm -rf "$WORKDIR"; }
trap cleanup EXIT

SRC="$WORKDIR/hello.php"
printf '%s\n' '<?php echo "hello\n";' > "$SRC"

# Extract GNU Build ID hex from readelf -n (empty when readelf missing / no note).
gnu_build_id() {
  local bin=$1
  if ! command -v readelf >/dev/null 2>&1; then
    echo ""
    return 0
  fi
  readelf -n "$bin" 2>/dev/null | awk '/Build ID:/ {print $3; exit}'
}

# One isolated build: distinct HOME + TMPDIR so path/env leaks cannot hide as
# "same tree twice" (#36399 Done-when: two-runner approximation on one host).
build_once() {
  local label=$1
  local root=$2
  local outdir="$root/out"
  local home="$root/home"
  local tmp="$root/tmp"
  mkdir -p "$outdir" "$home" "$tmp"
  (
    export HOME="$home"
    export TMPDIR="$tmp"
    export TMP="$tmp"
    export TEMP="$tmp"
    # Scrub host identity that linkers occasionally embed when not reproducible.
    unset USERNAME LOGNAME HOSTNAME 2>/dev/null || true
    export USER="phpc-repro-${label}"
    ./phpc build -o "$outdir/hello" "$SRC" >/dev/null
  )
  if [[ ! -x "$outdir/hello" ]]; then
    echo "verify-reproducible: missing binary $outdir/hello" >&2
    return 1
  fi
  # Runtime check — a wrong binary that happens to hash-match is still a failure.
  local got
  got=$("$outdir/hello")
  if [[ "$got" != $'hello\n' && "$got" != "hello" ]]; then
    echo "verify-reproducible: hello output mismatch: $(printf %q "$got")" >&2
    return 1
  fi
}

echo "verify-reproducible: building hello (isolation A)…"
build_once A "$WORKDIR/A" || exit 1
echo "verify-reproducible: building hello (isolation B, different HOME+TMPDIR)…"
build_once B "$WORKDIR/B" || exit 1

HA=$(sha256sum "$WORKDIR/A/out/hello" | awk '{print $1}')
HB=$(sha256sum "$WORKDIR/B/out/hello" | awk '{print $1}')
echo "  A sha256=$HA"
echo "  B sha256=$HB"
if [[ "$HA" != "$HB" ]]; then
  echo "verify-reproducible: FAIL — hello binaries differ across isolations (#36399)" >&2
  exit 1
fi

IDA=$(gnu_build_id "$WORKDIR/A/out/hello")
IDB=$(gnu_build_id "$WORKDIR/B/out/hello")
if [[ -z "$IDA" ]]; then
  echo "verify-reproducible: FAIL — missing NT_GNU_BUILD_ID (expected -Wl,--build-id=sha1) (#36399)" >&2
  if command -v readelf >/dev/null 2>&1; then
    readelf -n "$WORKDIR/A/out/hello" 2>&1 | head -40 >&2 || true
  fi
  exit 1
fi
if [[ "$IDA" != "$IDB" ]]; then
  echo "verify-reproducible: FAIL — GNU Build ID differs across isolations (A=$IDA B=$IDB) (#36399)" >&2
  exit 1
fi
echo "  build-id: $IDA (identical across isolations)"

echo "verify-reproducible: hello OK (byte-identical + build-id match)"

MINI_SHA=""
if [[ "$WITH_MINI" -eq 1 ]]; then
  echo "verify-reproducible: MiniWebApp (isolation A)…"
  mkdir -p "$WORKDIR/MA/home" "$WORKDIR/MA/tmp" "$WORKDIR/MA/out" \
           "$WORKDIR/MB/home" "$WORKDIR/MB/tmp" "$WORKDIR/MB/out"
  if ! (
    export HOME="$WORKDIR/MA/home" TMPDIR="$WORKDIR/MA/tmp" TMP="$WORKDIR/MA/tmp" TEMP="$WORKDIR/MA/tmp"
    unset USERNAME LOGNAME HOSTNAME 2>/dev/null || true
    export USER=phpc-repro-MA
    ./phpc build --project examples/003-MiniWebApp -o "$WORKDIR/MA/out/app" >/dev/null
  ); then
    echo "verify-reproducible: MiniWebApp build A failed" >&2
    exit 1
  fi
  echo "verify-reproducible: MiniWebApp (isolation B)…"
  if ! (
    export HOME="$WORKDIR/MB/home" TMPDIR="$WORKDIR/MB/tmp" TMP="$WORKDIR/MB/tmp" TEMP="$WORKDIR/MB/tmp"
    unset USERNAME LOGNAME HOSTNAME 2>/dev/null || true
    export USER=phpc-repro-MB
    ./phpc build --project examples/003-MiniWebApp -o "$WORKDIR/MB/out/app" >/dev/null
  ); then
    echo "verify-reproducible: MiniWebApp build B failed" >&2
    exit 1
  fi
  MA=$(sha256sum "$WORKDIR/MA/out/app" | awk '{print $1}')
  MB=$(sha256sum "$WORKDIR/MB/out/app" | awk '{print $1}')
  echo "  MiniWebApp A sha256=$MA"
  echo "  MiniWebApp B sha256=$MB"
  if [[ "$MA" != "$MB" ]]; then
    echo "verify-reproducible: FAIL — MiniWebApp binaries differ (#36399)" >&2
    exit 1
  fi
  MINI_SHA=$MA
  echo "verify-reproducible: MiniWebApp OK"
fi

if [[ "$JSON" -eq 1 ]]; then
  # Compact one-line JSON for release-readiness / CI streak tooling (#36399 / #36401).
  if [[ -n "$MINI_SHA" ]]; then
    printf '{"ok":true,"hello_sha256":"%s","build_id":"%s","miniwebapp_sha256":"%s","issue":36399}\n' \
      "$HA" "$IDA" "$MINI_SHA"
  else
    printf '{"ok":true,"hello_sha256":"%s","build_id":"%s","issue":36399}\n' \
      "$HA" "$IDA"
  fi
fi

echo "verify-reproducible: PASS"
exit 0
