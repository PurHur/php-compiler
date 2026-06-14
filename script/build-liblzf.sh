#!/usr/bin/env bash
# Build bundled liblzf for VmLzfNative FFI (.so) and AOT link (.a) (#6384).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/third_party/liblzf"
OUT="$ROOT/.libs"
mkdir -p "$OUT"
gcc -O2 -fPIC -c -DSTANDALONE -I"$SRC" "$SRC/lzf_c.c" -o "$OUT/lzf_c.o"
gcc -O2 -fPIC -c -DSTANDALONE -I"$SRC" "$SRC/lzf_d.c" -o "$OUT/lzf_d.o"
ar rcs "$OUT/liblzf.a" "$OUT/lzf_c.o" "$OUT/lzf_d.o"
gcc -O2 -fPIC -shared -o "$OUT/liblzf.so" "$OUT/lzf_c.o" "$OUT/lzf_d.o"
echo "built $OUT/liblzf.a and $OUT/liblzf.so"
