#!/usr/bin/env bash
#
# Verify SHA256SUMS (+ optional detached openssl signature) for release artifacts (#36399).
#
# Usage:
#   script/verify-release-artifacts.sh OUT_DIR
#   script/verify-release-artifacts.sh OUT_DIR SHA256SUMS
#
# Requires:
#   OUT_DIR/SHA256SUMS
#   files listed in SHA256SUMS (basename match under OUT_DIR)
# Optional:
#   OUT_DIR/SHA256SUMS.sig + OUT_DIR/SHA256SUMS.sig.pub.pem
#   PHPC_RELEASE_VERIFY_PUB=/path/to/pub.pem  (overrides .sig.pub.pem)
#
# PHPC_RELEASE_REQUIRE_SIGNATURE=1 fails when .sig is missing.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

if [[ "$#" -lt 1 || "$#" -gt 2 ]]; then
  echo "usage: $0 OUT_DIR [SHA256SUMS]" >&2
  exit 2
fi

OUT_DIR=$1
SUMS=${2:-"${OUT_DIR}/SHA256SUMS"}

if [[ ! -f "$SUMS" ]]; then
  echo "verify-release-artifacts: missing ${SUMS}" >&2
  exit 1
fi

# Checksums: run from OUT_DIR so basenames resolve.
(
  cd "$OUT_DIR"
  sha256sum -c "$(basename "$SUMS")"
)

SIG="${SUMS}.sig"
PUB="${PHPC_RELEASE_VERIFY_PUB:-${SUMS}.sig.pub.pem}"
REQUIRE="${PHPC_RELEASE_REQUIRE_SIGNATURE:-0}"

if [[ ! -f "$SIG" ]]; then
  if [[ "$REQUIRE" == "1" || "$REQUIRE" == "true" ]]; then
    echo "verify-release-artifacts: missing ${SIG} (PHPC_RELEASE_REQUIRE_SIGNATURE=1)" >&2
    exit 1
  fi
  echo "verify-release-artifacts: SHA256SUMS OK (no signature present)"
  exit 0
fi

if [[ ! -f "$PUB" ]]; then
  echo "verify-release-artifacts: missing public key ${PUB}" >&2
  exit 1
fi

if openssl dgst -sha256 -verify "$PUB" -signature "$SIG" "$SUMS" >/dev/null; then
  echo "verify-release-artifacts: SHA256SUMS + signature OK"
  exit 0
fi

echo "verify-release-artifacts: signature verification FAILED" >&2
exit 1
