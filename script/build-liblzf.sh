#!/usr/bin/env bash
# Build bundled liblzf shared library for VmLzfNative FFI (#6384).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/third_party/liblzf"
OUT="$ROOT/.libs"
mkdir -p "$OUT"
gcc -O2 -fPIC -shared -DSTANDALONE \
  -I"$SRC" \
  "$SRC/lzf_c.c" "$SRC/lzf_d.c" \
  -o "$OUT/liblzf.so"
echo "built $OUT/liblzf.so"
