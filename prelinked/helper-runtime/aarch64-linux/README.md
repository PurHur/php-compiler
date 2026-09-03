# aarch64-linux helper-runtime (#36391)

This directory is the committed helper-cache tier for `PHP_COMPILER_TARGET=aarch64-linux`
(see `lib/AOT/CompileTarget.php`).

LLVM object emit is TargetMachine-driven (triple `aarch64-unknown-linux-gnu`, CPU `generic`).
On an x86_64 host the AArch64 backend is initialized explicitly — it does **not** fall back
to host MCJIT (that would write x86_64 ELF into this tree). `Linker` still refuses a
non-native link.

Full unit corpus is not published here yet. Emit objects (no link) on any host with LLVM 9
AArch64, or refresh on a native aarch64 machine:

```bash
PHP_COMPILER_TARGET=aarch64-linux PHP_COMPILER_KEEP_OBJECT_FILE=1 \
  php script/emit-helper-runtime-object.php --unit=/ext/standard/…   # one unit, 20 min cap
# native aarch64 only:
PHP_COMPILER_TARGET=aarch64-linux make helper-runtime-prelink-refresh
```
