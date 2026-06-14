#!/usr/bin/env bash
# Fetch libLLVM-14, clang-14, linker, and crt bits for LLVM 14 migration (#174).
# Opt-in only: default CI still uses LLVM 9 (script/install-llvm9.sh).
# After PHPLLVM FFI supports LLVM 14, set PHP_COMPILER_LLVM_PATH to this tree.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LLVM_DIR="${PHP_COMPILER_LLVM14_INSTALL_DIR:-$ROOT/.llvm14}"

# Future Docker stage may ship a complete toolchain at /opt/llvm14.
if [[ -f /opt/llvm14/libLLVM-14.so.1 && -x /opt/llvm14/clang-14 ]]; then
  exit 0
fi

DEB_BASE="http://deb.debian.org/debian/pool/main/l/llvm-toolchain-14"
BINUTILS_BASE="http://deb.debian.org/debian/pool/main/b/binutils"
GCC12_BASE="http://deb.debian.org/debian/pool/main/g/gcc-12"
GLIBC_BASE="http://deb.debian.org/debian/pool/main/g/glibc"
VER="14.0.6-12"
BINUTILS_VER="2.40-2"
GCC_VER="12.2.0-14+deb12u1"
LIBC6_DEV_VER="2.36-9+deb12u14"

mkdir -p "$LLVM_DIR/gcc/12"
need=0
[[ -f "$LLVM_DIR/libLLVM-14.so.1" ]] || need=1
[[ -x "$LLVM_DIR/clang-14" ]] || need=1
[[ -x "$LLVM_DIR/ld" ]] || need=1
[[ -f "$LLVM_DIR/gcc/12/crtbegin.o" ]] || need=1
[[ -f "$LLVM_DIR/libedit.so.2" ]] || need=1
[[ -f "$LLVM_DIR/libz3.so.4" ]] || need=1
[[ -f "$LLVM_DIR/libjansson.so.4" ]] || need=1
[[ -f "$LLVM_DIR/libffi.so.8" ]] || need=1
need_headers=0
[[ -f "$LLVM_DIR/sysroot/usr/include/stdio.h" ]] || need_headers=1
if [[ "$need" -eq 0 && "$need_headers" -eq 0 ]]; then
  exit 0
fi

if ! command -v curl >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
  echo "curl or python3 is required to download the LLVM 14 toolchain" >&2
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

if [[ ! -f "$LLVM_DIR/libLLVM-14.so.1" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/libllvm14_${VER}_amd64.deb" libllvm14.deb)"
  install -m 644 "$dir/usr/lib/x86_64-linux-gnu/libLLVM-14.so.1" "$LLVM_DIR/libLLVM-14.so.1"
fi

if [[ ! -f "$LLVM_DIR/libffi.so.8" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/libf/libffi/libffi8_3.4.4-1_amd64.deb" libffi8.deb)"
  shopt -s nullglob
  for lib in "$dir"/usr/lib/x86_64-linux-gnu/libffi.so.8.*; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
    ln -sf "$(basename "$lib")" "$LLVM_DIR/libffi.so.8"
  done
  shopt -u nullglob
fi

if [[ ! -x "$LLVM_DIR/clang-14" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/clang-14_${VER}_amd64.deb" clang14.deb)"
  install -m 755 "$dir/usr/bin/clang-14" "$LLVM_DIR/clang-14"
fi

if [[ ! -f "$LLVM_DIR/libclang-cpp.so.14" ]]; then
  dir="$(fetch_deb "${DEB_BASE}/libclang-cpp14_${VER}_amd64.deb" libclang-cpp14.deb)"
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

if [[ ! -f "$LLVM_DIR/gcc/12/crtbegin.o" ]]; then
  dir="$(fetch_deb "${GCC12_BASE}/libgcc-12-dev_${GCC_VER}_amd64.deb" libgcc12.deb)"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/12/crtbegin.o" "$LLVM_DIR/gcc/12/crtbegin.o"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/12/crtend.o" "$LLVM_DIR/gcc/12/crtend.o"
  install -m 644 "$dir/usr/lib/gcc/x86_64-linux-gnu/12/libgcc.a" "$LLVM_DIR/gcc/12/libgcc.a"
fi

if [[ "$need_headers" -eq 1 && ! -d "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/12/include" ]]; then
  dir="$(fetch_deb "${GCC12_BASE}/libgcc-12-dev_${GCC_VER}_amd64.deb" libgcc12-headers.deb)"
  mkdir -p "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/12"
  cp -a "$dir/usr/lib/gcc/x86_64-linux-gnu/12/include" "$LLVM_DIR/sysroot/usr/lib/gcc/x86_64-linux-gnu/12/"
fi

if [[ "$need_headers" -eq 1 && ! -f "$LLVM_DIR/sysroot/usr/include/stdio.h" ]]; then
  dir="$(fetch_deb "${GLIBC_BASE}/libc6-dev_${LIBC6_DEV_VER}_amd64.deb" libc6-dev.deb)"
  mkdir -p "$LLVM_DIR/sysroot/usr"
  cp -a "$dir/usr/include" "$LLVM_DIR/sysroot/usr/include"
  if [[ -d "$dir/usr/include/x86_64-linux-gnu" ]]; then
    cp -a "$dir/usr/include/x86_64-linux-gnu" "$LLVM_DIR/sysroot/usr/include/"
  fi
fi

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

if [[ ! -f "$LLVM_DIR/libjansson.so.4" ]]; then
  dir="$(fetch_deb "http://deb.debian.org/debian/pool/main/j/jansson/libjansson4_2.14-2_amd64.deb" libjansson4.deb)"
  shopt -s nullglob
  for lib in "$dir"/usr/lib/x86_64-linux-gnu/libjansson.so.4.*; do
    install -m 644 "$lib" "$LLVM_DIR/$(basename "$lib")"
    ln -sf "$(basename "$lib")" "$LLVM_DIR/libjansson.so.4"
  done
  shopt -u nullglob
fi

echo "LLVM 14 toolchain installed under $LLVM_DIR"
echo "Note: PHPLLVM FFI still targets LLVM 9 by default (#174). Use this tree only after llvm14 FFI lands."
