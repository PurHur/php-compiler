# aarch64-linux helper-runtime (#36391)

This directory is the committed helper-cache tier for `PHP_COMPILER_TARGET=aarch64-linux`
(see `lib/AOT/CompileTarget.php`).

Objects are not published yet — emit on an aarch64 host (or cross toolchain) with:

```bash
PHP_COMPILER_TARGET=aarch64-linux make helper-runtime-prelink-refresh
```

Until then, `aot-smoke` / Linker refuse a non-native target rather than linking x86_64 objects.
