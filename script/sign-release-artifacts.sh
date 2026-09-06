#!/usr/bin/env bash
#
# Detached signature for release SHA256SUMS (#36399).
#
# Uses openssl RSA-SHA256 (`dgst -sha256 -sign`) — portable across OpenSSL 1.1/3
# in the pinned image. Prefer a durable key via:
#   PHPC_RELEASE_SIGNING_KEY=/path/to/rsa.pem
#   PHPC_RELEASE_SIGNING_PUB=/path/to/rsa.pub.pem   # optional; written next to sig
#
# Without PHPC_RELEASE_SIGNING_KEY, generates an ephemeral 2048-bit RSA keypair
# under OUT_DIR (local smoke / CI that only checks the verify path).
#
# Usage:
#   script/sign-release-artifacts.sh OUT_DIR
#   script/sign-release-artifacts.sh OUT_DIR SHA256SUMS
#
# Writes:
#   OUT_DIR/SHA256SUMS.sig          — openssl dgst -sha256 -sign (binary)
#   OUT_DIR/SHA256SUMS.sig.pub.pem  — public key used to verify
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
  echo "sign-release-artifacts: missing ${SUMS} — run write-release-checksums.sh first" >&2
  exit 1
fi
mkdir -p "$OUT_DIR"

SIG="${SUMS}.sig"
PUB_OUT="${SUMS}.sig.pub.pem"
KEY="${PHPC_RELEASE_SIGNING_KEY:-}"
PUB_IN="${PHPC_RELEASE_SIGNING_PUB:-}"
EPHEMERAL=0

if [[ -z "$KEY" ]]; then
  EPHEMERAL=1
  KEY="${OUT_DIR}/.phpc-release-signing-key.pem"
  PUB_IN="${OUT_DIR}/.phpc-release-signing-pub.pem"
  openssl genrsa -out "$KEY" 2048 2>/dev/null
  openssl rsa -in "$KEY" -pubout -out "$PUB_IN" 2>/dev/null
  echo "sign-release-artifacts: generated ephemeral RSA-2048 key (set PHPC_RELEASE_SIGNING_KEY for durable releases)" >&2
fi

if [[ ! -f "$KEY" ]]; then
  echo "sign-release-artifacts: signing key not found: ${KEY}" >&2
  exit 1
fi

openssl dgst -sha256 -sign "$KEY" -out "$SIG" "$SUMS"

if [[ -n "$PUB_IN" && -f "$PUB_IN" ]]; then
  cp -f "$PUB_IN" "$PUB_OUT"
else
  openssl rsa -in "$KEY" -pubout -out "$PUB_OUT" 2>/dev/null
fi

# Never leave an ephemeral private key next to a published tarball.
if [[ "$EPHEMERAL" -eq 1 ]]; then
  rm -f "$KEY" "${OUT_DIR}/.phpc-release-signing-key.pem"
  # Keep only the published pub next to the sig (drop ephemeral pub twin if distinct).
  if [[ "$PUB_IN" != "$PUB_OUT" ]]; then
    rm -f "$PUB_IN"
  fi
fi

echo "sign-release-artifacts: wrote ${SIG} (+ $(basename "$PUB_OUT"))"
exit 0
