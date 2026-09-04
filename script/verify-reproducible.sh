#!/usr/bin/env bash
#
# Verify AOT binaries are bit-identical across two builds (#36399).
#
# Builds a tiny hello program twice with different TMPDIR trees and compares
# sha256 of the executables. Optionally checks that a GNU build-id note is present.
#
# Usage:
#   script/verify-reproducible.sh
#   script/verify-reproducible.sh --with-miniwebapp   # also build examples/003-MiniWebApp (slow)
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
for arg in "$@"; do
  case "$arg" in
    --with-miniwebapp) WITH_MINI=1 ;;
    -h|--help)
      sed -n '2,14p' "$0"
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

build_once() {
  local outdir=$1
  mkdir -p "$outdir"
  TMPDIR="$outdir" ./phpc build -o "$outdir/hello" "$SRC" >/dev/null
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

echo "verify-reproducible: building hello (pass A)…"
build_once "$WORKDIR/A" || exit 1
echo "verify-reproducible: building hello (pass B, different TMPDIR)…"
build_once "$WORKDIR/B" || exit 1

HA=$(sha256sum "$WORKDIR/A/hello" | awk '{print $1}')
HB=$(sha256sum "$WORKDIR/B/hello" | awk '{print $1}')
echo "  A sha256=$HA"
echo "  B sha256=$HB"
if [[ "$HA" != "$HB" ]]; then
  echo "verify-reproducible: FAIL — hello binaries differ across TMPDIR (#36399)" >&2
  exit 1
fi

if command -v readelf >/dev/null 2>&1; then
  if ! readelf -n "$WORKDIR/A/hello" 2>/dev/null | grep -q 'NT_GNU_BUILD_ID'; then
    echo "verify-reproducible: FAIL — missing NT_GNU_BUILD_ID (expected -Wl,--build-id=sha1) (#36399)" >&2
    readelf -n "$WORKDIR/A/hello" 2>&1 | head -40 >&2 || true
    exit 1
  fi
  echo "  build-id: present (NT_GNU_BUILD_ID)"
fi

echo "verify-reproducible: hello OK (byte-identical + build-id)"

if [[ "$WITH_MINI" -eq 1 ]]; then
  echo "verify-reproducible: MiniWebApp (pass A)…"
  mkdir -p "$WORKDIR/MA" "$WORKDIR/MB"
  if ! TMPDIR="$WORKDIR/MA" ./phpc build --project examples/003-MiniWebApp -o "$WORKDIR/MA/app" >/dev/null; then
    echo "verify-reproducible: MiniWebApp build A failed" >&2
    exit 1
  fi
  echo "verify-reproducible: MiniWebApp (pass B)…"
  if ! TMPDIR="$WORKDIR/MB" ./phpc build --project examples/003-MiniWebApp -o "$WORKDIR/MB/app" >/dev/null; then
    echo "verify-reproducible: MiniWebApp build B failed" >&2
    exit 1
  fi
  MA=$(sha256sum "$WORKDIR/MA/app" | awk '{print $1}')
  MB=$(sha256sum "$WORKDIR/MB/app" | awk '{print $1}')
  echo "  MiniWebApp A sha256=$MA"
  echo "  MiniWebApp B sha256=$MB"
  if [[ "$MA" != "$MB" ]]; then
    echo "verify-reproducible: FAIL — MiniWebApp binaries differ (#36399)" >&2
    exit 1
  fi
  echo "verify-reproducible: MiniWebApp OK"
fi

echo "verify-reproducible: PASS"
exit 0
