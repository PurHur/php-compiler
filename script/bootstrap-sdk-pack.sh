#!/usr/bin/env bash
# Bootstrap SDK release tarball — gen-0 prelink + vendor objects (#15602).
#
# Creates build/php-compiler-bootstrap-{spine-sha-prefix}.tar.gz from committed
# prelinked/bootstrap-gen0/, prelinked/bootstrap-vendor/*.o, and manifest files.
#
# Usage:
#   ./script/bootstrap-sdk-pack.sh [TAG]
#   make bootstrap-sdk-pack
#   make bootstrap-sdk-pack TAG=v1.0.0
#
# Without TAG: build/php-compiler-bootstrap-{spine-sha-prefix}.tar.gz
# With TAG:     build/php-compiler-bootstrap-{TAG}-linux-x86_64.tar.gz (GitHub Release asset)
#
# Extract at repo root (or empty workdir) to seed Tier 1 cold start:
#   tar xzf build/php-compiler-bootstrap-*.tar.gz
#
# See docs/bootstrap-dev-workflow.md
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

GEN0="${ROOT}/prelinked/bootstrap-gen0"
VENDOR="${ROOT}/prelinked/bootstrap-vendor"
STAMP="${GEN0}/.m3_compiler_lib_sidecar.sha"
GEN0_MANIFEST="${GEN0}/manifest.json"
VENDOR_MANIFEST="${VENDOR}/manifest.json"

for required in \
  "${STAMP}" \
  "${GEN0_MANIFEST}" \
  "${VENDOR_MANIFEST}" \
  "${GEN0}/bin-compile-aot" \
  "${VENDOR}/ircmaxell-php-cfg.o" \
  "${VENDOR}/ircmaxell-php-llvm.o" \
  "${VENDOR}/ircmaxell-php-types.o"
do
  if [[ ! -e "${required}" ]]; then
    echo "bootstrap-sdk-pack: missing ${required}" >&2
    exit 1
  fi
done

SHA="$(tr -d '[:space:]' <"${STAMP}")"
if [[ ${#SHA} -lt 8 ]]; then
  echo "bootstrap-sdk-pack: invalid spine sidecar stamp: ${STAMP}" >&2
  exit 1
fi

PREFIX="${SHA:0:12}"
TAG="${1:-}"
if [[ -n "${TAG}" ]]; then
  if [[ ! "${TAG}" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "bootstrap-sdk-pack: invalid TAG (use alnum, dot, dash, underscore): ${TAG}" >&2
    exit 1
  fi
  OUT="${ROOT}/build/php-compiler-bootstrap-${TAG}-linux-x86_64.tar.gz"
else
  OUT="${ROOT}/build/php-compiler-bootstrap-${PREFIX}.tar.gz"
fi
mkdir -p "${ROOT}/build"

rm -f "${OUT}"
tar -C "${ROOT}" -czf "${OUT}" \
  prelinked/bootstrap-gen0 \
  prelinked/bootstrap-vendor/manifest.json \
  prelinked/bootstrap-vendor/ircmaxell-php-cfg.o \
  prelinked/bootstrap-vendor/ircmaxell-php-llvm.o \
  prelinked/bootstrap-vendor/ircmaxell-php-types.o

BYTES="$(wc -c <"${OUT}" | tr -d '[:space:]')"
if [[ -n "${TAG}" ]]; then
  echo "bootstrap-sdk-pack: OK ${OUT} (${BYTES} bytes, tag ${TAG}, spine ${PREFIX}…)"
else
  echo "bootstrap-sdk-pack: OK ${OUT} (${BYTES} bytes, spine ${PREFIX}…)"
fi
