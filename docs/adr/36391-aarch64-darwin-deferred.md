# ADR: aarch64-darwin AOT target (#36391)

- Status: Accepted (defer)
- Date: 2026-09-04
- Parent: #36391 · related #36220

## Decision

`PHP_COMPILER_TARGET=aarch64-darwin` remains a **data-only** CompileTarget
(`lib/AOT/CompileTarget.php` SPECS + aliases). Object emit / link / helper-cache
publish for Darwin wait for the LLVM 22 migration (#36220).

## Why

LLVM 9 on macOS is not a supported bootstrap or release toolchain
(`docs/bootstrap-sdk-platform.md`). Shipping a half-working Darwin TargetMachine
path would invent a third platform without a runner or prelinked corpus.

## Consequences

- `phpc doctor` prints the target when set; `canLinkOnThisHost()` is false.
- Linux aarch64 (`aarch64-linux`) is the arm64 Ship path: helper-cache tier +
  multi-arch `ghcr.io/purhur/phpc` via buildx `PLATFORMS`.
- Revisit when LLVM 22 FFI bindings land and a macOS CI host exists.
