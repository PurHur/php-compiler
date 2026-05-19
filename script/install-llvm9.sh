#!/usr/bin/env bash
# Fetch libLLVM-9, clang-9, linker, and crt bits for AOT/JIT on hosts with only LLVM 17+.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LLVM_DIR="$ROOT/.llvm"
DEB_BASE="http://deb.debian.org/debian/pool/main/l/llvm-toolchain-9"
BINUTILS_BASE="http://deb.debian.org/debian/pool/main/b/binutils"
GCC9_BASE="http://deb.debian.org/debian/pool/main/g/gcc-9"
GLIBC_BASE="http://deb.debian.org/debian/pool/main/g/glibc"
VER="9.0.1-16.1"
BINUTILS_VER="2.40-2"
GCC_VER="9.3.0-22"
LIBC6_DEV_VER="2.36-9+deb12u14"

mkdir -p "$LLVM_DIR/gcc/9"
need=0
[[ -f "$LLVM_DIR/libLLVM-9.so.1" ]] || need=1
[[ -x "$LLVM_DIR/clang-9" ]] || need=1
[[ -x "$LLVM_DIR/ld" ]] || need=1
[[ -f "$LLVM_DIR/gcc/9/crtbegin.o" ]] || need=1
[[ -f "$LLVM_DIR/libedit.so.2" ]] || need=1
[[ -f "$LLVM_DIR/libz3.so.4" ]] || need=1
[[ -f "$LLVM_DIR/libjansson.so.4" ]] || need=1
need_headers=0
[[ -f "$LLVM_DIR/sysroot/usr/include/stdio.h" ]] || need_headers=1
if [[ "$need" -eq 0 && "$need_headers" -eq 0 ]]; then
  exit 0
fi

if ! command -v curl >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
  echo "curl or python3 is required to download the LLVM 9 toolchain" >&2
  exit 1
fi
if ! command -v dpkg-deb >/dev/null 2>&1; then
  echo "dpkg-deb is required to extract .deb packages" >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fetch_deb() {
  local url="$1" out="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$TMP/$out" "$url"
  else
    python3 -c "import urllib.request; urllib.request.urlretrieve('$url', '$TMP/$out')"
  fi
  dpkg-deb -x "$TMP/$out" "$TMP/extract"
  rm -f "$TMP/$out"
  echo "$TMP/extract"
}

if [[ ! -f "$LLVM_DIR/libLLVM-9.so.1" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/libllvm9_${VER}_amd64.deb" libllvm9.deb)"
  install -m 644 "$dir/usr/lib/x86_64-linux-gnu/libLLVM-9.so.1" "$LLVM_DIR/libLLVM-9.so.1"
fi

if [[ ! -f "$LLVM_DIR/libffi.so.7" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/libf/libffi/libffi7_3.3-6_amd64.deb" libffi7.deb)"
  install -m 644 "$dir/usr/lib/x86_64-linux-gnu/libffi.so.7" "$LLVM_DIR/libffi.so.7"
fi

if [[ ! -x "$LLVM_DIR/clang-9" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/clang-9_${VER}_amd64.deb" clang9.deb)"
  install -m 755 "$dir/usr/bin/clang-9" "$LLVM_DIR/clang-9"
fi

if [[ ! -f "$LLVM_DIR/libclang-cpp.so.9" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/libclang-cpp9_${VER}_amd64.deb" libclang-cpp9.deb)"
  shopt -s nullglob
  for lib in "$dir"/usr/lib/x86_64-linux-gnu/libclang-cpp*.so*; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
  done
  shopt -u nullglob
fi

if [[ ! -x "$LLVM_DIR/ld" ]]; then
  dir="$(fetch_deb "${BINUTILS_BASE}/binutils-x86-64-linux-gnu_${BINUTILS_VER}_amd64.deb" binutils-x86.deb)"
  install -m 755 "$dir/usr/bin/x86_64-linux-gnu-ld" "$LLVM_DIR/ld"
  dir="$(fetch_deb "${BINUTILS_BASE}/libbinutils_${BINUTILS_VER}_amd64.deb" libbinutils.deb)"
  while IFS= read -r -d '' lib; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
  done < <(find "$dir/usr/lib/x86_64-linux-gnu" -maxdepth 1 \( -name 'libbfd*.so*' -o -name 'libopcodes*.so*' -o -name 'libsframe*.so*' \) -print0)
fi

if [[ ! -f "$LLVM_DIR/gcc/9/crtbegin.o" ]]; then
  dir="$(fetch_deb "${GCC9_BASE}/libgcc-9-dev_${GCC_VER}_amd64.deb" libgcc9.deb)"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/9/crtbegin.o" "$LLVM_DIR/gcc/9/crtbegin.o"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/9/crtend.o" "$LLVM_DIR/gcc/9/crtend.o"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/9/libgcc.a" "$LLVM_DIR/gcc/9/libgcc.a"
fi

if [[ "$need_headers" -eq 1 && ! -d "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/9/include" ]]; then
  dir="$(fetch_deb "${GCC9_BASE}/libgcc-9-dev_${GCC_VER}_amd64.deb" libgcc9-headers.deb)"
  mkdir -p "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/9"
  cp -a "$dir/usr/lib/gcc/x86_64-linux-gnu/9/include" "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/9/"
fi

if [[ "$need_headers" -eq 1 && ! -f "$LLVM_DIR/sysroot/usr/include/stdio.h" ]]; then
  dir="$(fetch_deb "${GLIBC_BASE}/libc6-dev_${LIBC6_DEV_VER}_amd64.deb" libc6-dev.deb)"
  mkdir -p "$LLVM_DIR/sysroot/usr"
  cp -a "$dir/usr/include" "$LLVM_DIR/sysroot/usr/include"
  if [[ -d "$dir/usr/include/x86_64-linux-gnu" ]]; then
    cp -a "$dir/usr/include/x86_64-linux-gnu" "$LLVM_DIR/sysroot/usr/include/"
  fi
fi

# libLLVM-9.so.1 also needs libedit and libz3 at load time (not always on minimal images).
if [[ ! -f "$LLVM_DIR/libedit.so.2" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/libe/libedit/libedit2_3.1-20221030-2_amd64.deb" libedit2.deb)"
  shopt -s nullglob
  for lib in "$dir"/usr/lib/x86_64-linux-gnu/libedit.so.2.*; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
    ln -sf "$(basename "$lib")" "$LLVM_DIR/libedit.so.2"
  done
  shopt -u nullglob
fi

if [[ ! -f "$LLVM_DIR/libz3.so.4" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/z/z3/libz3-4_4.8.12-3.1_amd64.deb" libz3-4.deb)"
  install -m 644 "$dir/usr/lib/x86_64-linux-gnu/libz3.so.4" "$LLVM_DIR/libz3.so.4"
fi

# Bundled ld (binutils 2.40) needs libjansson at runtime on minimal images.
if [[ ! -f "$LLVM_DIR/libjansson.so.4" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/j/jansson/libjansson4_2.14-2_amd64.deb" libjansson4.deb)"
  shopt -s nullglob
  for lib in "$dir"/usr/lib/x86_64-linux-gnu/libjansson.so.4.*; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
    ln -sf "$(basename "$lib")" "$LLVM_DIR/libjansson.so.4"
  done
  shopt -u nullglob
fi

echo "LLVM 9 toolchain installed under $LLVM_DIR"
