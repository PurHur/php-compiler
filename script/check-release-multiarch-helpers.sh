#!/usr/bin/env bash
# Gate: release staging plans both Linux helper arches + multi-arch flags (#36391).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

out="$(./script/build-phpc-release-image.sh --dry-run 2>&1)"
echo "$out" | grep -q 'stage prelinked/helper-runtime/x86_64-linux'
echo "$out" | grep -q 'stage prelinked/helper-runtime/aarch64-linux'
echo "$out" | grep -q 'multi-arch: PLATFORMS=linux/amd64,linux/arm64'

push_out="$(PLATFORMS=linux/amd64,linux/arm64 ./script/build-phpc-release-image.sh --dry-run --push 2>&1)"
echo "$push_out" | grep -q 'buildx --platform linux/amd64,linux/arm64 --push'

df="$(cat Docker/release/Dockerfile)"
echo "$df" | grep -q 'TARGETARCH'
echo "$df" | grep -q 'keep=aarch64-linux'
echo "$df" | grep -q 'keep=x86_64-linux'

test -f docs/adr/36391-aarch64-darwin-deferred.md

echo "check-release-multiarch-helpers: OK"
