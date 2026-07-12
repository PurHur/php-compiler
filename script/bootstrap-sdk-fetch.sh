#!/usr/bin/env bash
# Fetch Bootstrap SDK tarball and extract prelinked/ seeds (#15602).
#
# Usage:
#   ./script/bootstrap-sdk-fetch.sh URL_OR_PATH
#   PHP_COMPILER_BOOTSTRAP_SDK=https://…/php-compiler-bootstrap-v1.0-linux-x86_64.tar.gz ./script/bootstrap-sdk-fetch.sh
#
# Accepts http(s) URLs, file:// URIs, or a local path to .tar.gz.
# Extracts at repo root: prelinked/bootstrap-gen0/ + prelinked/bootstrap-vendor/*.o
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXTRACT_ROOT="${PHP_COMPILER_BOOTSTRAP_SDK_ROOT:-${ROOT}}"
cd "${EXTRACT_ROOT}"

URL="${1:-${PHP_COMPILER_BOOTSTRAP_SDK:-}}"
if [[ -z "${URL}" ]]; then
  echo "bootstrap-sdk-fetch: missing URL (arg or PHP_COMPILER_BOOTSTRAP_SDK)" >&2
  exit 1
fi

TMP="${EXTRACT_ROOT}/build/.bootstrap-sdk-fetch-$$"
mkdir -p "${EXTRACT_ROOT}/build" "${TMP}"
cleanup() { rm -rf "${TMP}"; }
trap cleanup EXIT

resolve_tarball() {
  local src="${1}"
  if [[ -f "${src}" ]]; then
    printf '%s\n' "${src}"
    return 0
  fi
  if [[ "${src}" == file://* ]]; then
    local path="${src#file://}"
    if [[ ! -f "${path}" ]]; then
      echo "bootstrap-sdk-fetch: file not found: ${path}" >&2
      return 1
    fi
    printf '%s\n' "${path}"
    return 0
  fi
  if [[ "${src}" != http://* && "${src}" != https://* ]]; then
    echo "bootstrap-sdk-fetch: unsupported URL scheme (use http(s), file://, or local path): ${src}" >&2
    return 1
  fi
  local dest="${TMP}/sdk.tar.gz"
  if ! curl -fsSL -o "${dest}" "${src}"; then
    echo "bootstrap-sdk-fetch: curl failed for ${src}" >&2
    return 1
  fi
  printf '%s\n' "${dest}"
}

TARBALL="$(resolve_tarball "${URL}")" || exit 1

LIST="${TMP}/tar-list.txt"
tar -tzf "${TARBALL}" >"${LIST}"
if ! grep -q '^prelinked/bootstrap-gen0/' "${LIST}"; then
  echo "bootstrap-sdk-fetch: tarball missing prelinked/bootstrap-gen0/ (got $(head -1 "${LIST}"))" >&2
  exit 1
fi

tar -C "${EXTRACT_ROOT}" -xzf "${TARBALL}"

GEN0="${EXTRACT_ROOT}/prelinked/bootstrap-gen0"
STAMP="${GEN0}/.m3_compiler_lib_sidecar.sha"
DRIVER="${GEN0}/bin-compile-aot"
for required in "${STAMP}" "${DRIVER}" "${GEN0}/manifest.json"; do
  if [[ ! -e "${required}" ]]; then
    echo "bootstrap-sdk-fetch: extract incomplete — missing ${required}" >&2
    exit 1
  fi
done

SHA="$(tr -d '[:space:]' <"${STAMP}")"
PREFIX="${SHA:0:12}"
echo "bootstrap-sdk-fetch: OK prelinked/bootstrap-gen0 (spine ${PREFIX}…, driver $(wc -c <"${DRIVER}" | tr -d '[:space:]') bytes)"
