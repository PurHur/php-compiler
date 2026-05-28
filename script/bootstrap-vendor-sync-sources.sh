#!/usr/bin/env bash
# Refresh committed vendor lib/ snapshot for M5 cold-boot AOT rebuild (#2881).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${ROOT}/prelinked/bootstrap-vendor/sources"
for pkg in php-cfg php-types; do
  src="${ROOT}/vendor/ircmaxell/${pkg}/lib"
  if [[ ! -d "${src}" ]]; then
    echo "bootstrap-vendor-sync-sources: missing ${src} (composer install)" >&2
    exit 1
  fi
  rm -rf "${DEST}/ircmaxell/${pkg}"
  mkdir -p "${DEST}/ircmaxell/${pkg}"
  cp -a "${src}" "${DEST}/ircmaxell/${pkg}/"
done
llvm_root="${ROOT}/vendor/ircmaxell/php-llvm"
if [[ ! -d "${llvm_root}/lib" || ! -d "${llvm_root}/ffi" ]]; then
  echo "bootstrap-vendor-sync-sources: missing ${llvm_root}/lib or ffi (composer install)" >&2
  exit 1
fi
rm -rf "${DEST}/ircmaxell/php-llvm"
mkdir -p "${DEST}/ircmaxell/php-llvm"
cp -a "${llvm_root}/lib" "${llvm_root}/ffi" "${DEST}/ircmaxell/php-llvm/"
parser_src="${ROOT}/vendor/nikic/php-parser/lib"
if [[ ! -d "${parser_src}" ]]; then
  echo "bootstrap-vendor-sync-sources: missing ${parser_src} (composer install)" >&2
  exit 1
fi
rm -rf "${DEST}/nikic/php-parser"
mkdir -p "${DEST}/nikic/php-parser"
cp -a "${parser_src}" "${DEST}/nikic/php-parser/"
count="$(find "${DEST}" -name '*.php' | wc -l)"
echo "bootstrap-vendor-sync-sources: ${count} PHP files under prelinked/bootstrap-vendor/sources"
