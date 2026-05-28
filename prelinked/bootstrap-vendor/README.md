# M5 vendor prelink artifacts (#1416)

Committed **literal-require bundles** live under `test/bootstrap-vendor-prelink/generated/`.
This directory holds the **manifest** and, when AOT succeeds, native `.o` / `.a` files.

```bash
php script/bootstrap-vendor-objects.php              # refresh bundles + manifest
make bootstrap-vendor-objects                        # AOT compile (LLVM 9; PHP_COMPILER_VENDOR_PRELINK=1, no composer autoload — #2849)
```

`manifest.json` records per-package `status` (`bundle_ok`, `compile_failed`, `object_ok`, …).
`lib/AOT/Linker.php::prelinkedVendorObjectPaths()` links only `object_ok` entries.

**Cold boot without `vendor/`:** committed `sources/ircmaxell/*/lib` (patched php-cfg/php-llvm/php-types) lets
`php script/bootstrap-vendor-objects.php --compile` rebuild missing `.o` via symlinked `vendor/ircmaxell/*` — no
`vendor/autoload.php` ([#2881](https://github.com/PurHur/php-compiler/issues/2881)). Refresh sources after
`composer install` + `script/apply-patches.sh`:

```bash
bash script/bootstrap-vendor-sync-sources.sh
```
