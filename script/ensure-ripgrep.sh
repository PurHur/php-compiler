#!/usr/bin/env bash
# Ensure `rg` is on PATH for gate scripts (#36248). Prefers a system package;
# otherwise fetches the pinned musl static build into tools/bin/ (gitignored).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN_DIR="${ROOT}/tools/bin"
RG_VER="13.0.0"
RG_URL="https://github.com/BurntSushi/ripgrep/releases/download/${RG_VER}/ripgrep-${RG_VER}-x86_64-unknown-linux-musl.tar.gz"
# sha256 of the release tarball (BurntSushi/ripgrep 13.0.0 musl)
RG_SHA256="ee4e0751ab108b6da4f47c52da187d5177dc371f0f512a7caaec5434e711c091"

if command -v rg >/dev/null 2>&1; then
  exit 0
fi

if [[ -x "${BIN_DIR}/rg" ]]; then
  export PATH="${BIN_DIR}:${PATH}"
  exit 0
fi

mkdir -p "${BIN_DIR}"
tmp="$(mktemp -d)"
trap 'rm -rf "${tmp}"' EXIT
archive="${tmp}/rg.tgz"
if command -v curl >/dev/null 2>&1; then
  curl -fsSL -o "${archive}" "${RG_URL}"
elif command -v wget >/dev/null 2>&1; then
  wget -q -O "${archive}" "${RG_URL}"
else
  echo "ensure-ripgrep: curl or wget required to fetch ripgrep (#36248)" >&2
  exit 2
fi

if command -v sha256sum >/dev/null 2>&1; then
  got="$(sha256sum "${archive}" | awk '{print $1}')"
  if [[ "${got}" != "${RG_SHA256}" ]]; then
    echo "ensure-ripgrep: sha256 mismatch (got ${got}, want ${RG_SHA256})" >&2
    exit 2
  fi
fi

tar -xzf "${archive}" -C "${tmp}"
cp "${tmp}/ripgrep-${RG_VER}-x86_64-unknown-linux-musl/rg" "${BIN_DIR}/rg"
chmod +x "${BIN_DIR}/rg"
export PATH="${BIN_DIR}:${PATH}"
echo "ensure-ripgrep: installed ${BIN_DIR}/rg (${RG_VER})" >&2
