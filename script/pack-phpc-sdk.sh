#!/usr/bin/env bash
# Pack a host-PHP SDK tarball from a built phpc release image (#36390).
#
# Requires: docker, a built PHPC_RELEASE_IMAGE, host PHP 8.2+ at runtime (not baked in).
# Bundles: /opt/phpc tree + /opt/llvm9 + a bin/phpc wrapper that points at bundled LLVM.
#
# Usage:
#   ./script/build-phpc-release-image.sh
#   ./script/pack-phpc-sdk.sh
#   # → build/phpc-<tag>-x86_64-linux.tar.zst (or .tar.gz if zstd missing)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

: "${PHPC_RELEASE_TAG:=dev}"
: "${PHPC_RELEASE_IMAGE:=ghcr.io/purhur/phpc:${PHPC_RELEASE_TAG}}"
ARCH="$(uname -m)"
case "$ARCH" in
  x86_64|amd64) ARCH_LABEL=x86_64-linux ;;
  aarch64|arm64) ARCH_LABEL=aarch64-linux ;;
  *) echo "pack-phpc-sdk: unsupported arch ${ARCH}" >&2; exit 1 ;;
esac

if ! docker image inspect "$PHPC_RELEASE_IMAGE" >/dev/null 2>&1; then
  echo "pack-phpc-sdk: image ${PHPC_RELEASE_IMAGE} missing — run ./script/build-phpc-release-image.sh" >&2
  exit 1
fi

OUT_DIR="${REPO_ROOT}/build"
mkdir -p "$OUT_DIR"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/phpc-sdk-pack.XXXXXX")"
CID=""
cleanup() {
  [[ -n "$CID" ]] && docker rm -f "$CID" >/dev/null 2>&1 || true
  rm -rf "$STAGE"
}
trap cleanup EXIT

CID="$(docker create "$PHPC_RELEASE_IMAGE")"
mkdir -p "${STAGE}/phpc-sdk"
docker cp "${CID}:/opt/phpc/." "${STAGE}/phpc-sdk/"
docker cp "${CID}:/opt/llvm9/." "${STAGE}/phpc-sdk/llvm9/"

# Host wrapper: find php8.2+/php and use bundled LLVM.
cat > "${STAGE}/phpc-sdk/bin/phpc-host" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PHP_COMPILER_LLVM_PATH="${PHP_COMPILER_LLVM_PATH:-$ROOT/llvm9}"
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}:${PHP_COMPILER_LLVM_PATH}"
export PHPC_INVOKE_CWD="${PHPC_INVOKE_CWD:-${PWD}}"
PHP_BIN=""
for c in php8.2 php8.3 php8.4 php; do
  if command -v "$c" >/dev/null 2>&1; then PHP_BIN="$(command -v "$c")"; break; fi
done
if [[ -z "$PHP_BIN" ]]; then
  echo "phpc: need PHP 8.2+ on PATH (php8.2 or php)" >&2
  exit 127
fi
cd "$ROOT"
exec "$PHP_BIN" bin/phpc.php "$@"
EOF
chmod +x "${STAGE}/phpc-sdk/bin/phpc-host"
# Prefer the host wrapper name `phpc` at SDK root for PATH installs.
cp "${STAGE}/phpc-sdk/bin/phpc-host" "${STAGE}/phpc-sdk/phpc-host"
chmod +x "${STAGE}/phpc-sdk/phpc-host"

OUT_BASE="${OUT_DIR}/phpc-${PHPC_RELEASE_TAG}-${ARCH_LABEL}"
if command -v zstd >/dev/null 2>&1; then
  OUT="${OUT_BASE}.tar.zst"
  tar -C "$STAGE" -cf - phpc-sdk | zstd -T0 -q -o "$OUT"
else
  OUT="${OUT_BASE}.tar.gz"
  tar -C "$STAGE" -czf "$OUT" phpc-sdk
fi

BYTES="$(wc -c < "$OUT" | tr -d ' ')"
echo "pack-phpc-sdk: wrote ${OUT} (${BYTES} bytes)"
# Soft budget from #36390 Done-when (≤ 1 GB download).
if [[ "$BYTES" -gt 1073741824 ]]; then
  echo "pack-phpc-sdk: WARNING — tarball exceeds 1 GiB cold-install budget (#36390)" >&2
  exit 1
fi
# Release checksums + detached openssl signature next to the tarball (#36399).
"${REPO_ROOT}/script/write-release-checksums.sh" "$OUT_DIR" "$OUT"
"${REPO_ROOT}/script/sign-release-artifacts.sh" "$OUT_DIR"
PHPC_RELEASE_REQUIRE_SIGNATURE=1 "${REPO_ROOT}/script/verify-release-artifacts.sh" "$OUT_DIR"
echo "pack-phpc-sdk: extract and run: ./phpc-host doctor   # or: ./phpc-host build -o hello hello.php"
