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

# Cross-target object-emit smoke must stay wired (#36391 — x86 CI cannot link arm64).
test -x script/aot-smoke-cross-emit.sh
grep -q 'PHP_COMPILER_KEEP_OBJECT_FILE=1' script/aot-smoke-cross-emit.sh
grep -q 'readElfMachine' script/aot-smoke-cross-emit.sh
grep -q 'empty result set is not a pass' script/aot-smoke-cross-emit.sh

# Curated aarch64 VM_* seed (parity with x86_64-linux VM_* units) — empty is not a pass.
test -x script/seed-aarch64-helper-runtime.sh
./script/seed-aarch64-helper-runtime.sh --check

echo "check-release-multiarch-helpers: OK"
