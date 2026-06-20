# M5 fast path — incremental native emit (M3 compile driver)

**Primary development gate (Jun 2026):** use **`make north-star5-verify-fast`** (~1–2 min) and **`make bootstrap-selfhost-vm-driver-execute-probe`** (~20ms) for daily M5/LLVM iteration. Reserve **`north-star5-verify --strict`** (~1h) for pre-merge bootstrap PRs only. Cursor rule: `.cursor/rules/m5-fast-development.mdc`.

**Project north star (full ladder):** [self-host-target.md](self-host-target.md)

Issue [#1056](https://github.com/PurHur/php-compiler/issues/1056); link segfault fix [#1402](https://github.com/PurHur/php-compiler/issues/1402).

## Goal

Self-host HelloWorld bundle emits AOT without Zend `bin/compile.php` fallback (`BOOTSTRAP_M3_HELLOWORLD_STRICT=1`).

## Tactic

Expand `JIT::isM3CompileDriverRealLoweringName()` **one function at a time** while `PHP_COMPILER_M3_COMPILE_DRIVER=1` links `test/selfhost/compiler_helloworld_smoke/compile_driver.php`.

**Allowlist SSOT:** committed `script/m3-allowlist-snapshot.txt` mirrors `lib/JIT.php` allow/deny names; CI fails on drift (`M3_ALLOWLIST_SYNC_GATE=1`, issue [#1905](https://github.com/PurHur/php-compiler/issues/1905); doc table sync `BOOTSTRAP_M5_DOC_SYNC_GATE=1`, [#1984](https://github.com/PurHur/php-compiler/issues/1984)). Regenerate after each batch: `php script/bootstrap-m3-allowlist-snapshot.php --write` then update this doc Step 2 / deny tables. Batch tracker: [#1768](https://github.com/PurHur/php-compiler/issues/1768).

Supporting fixes from #1402:

- `jitFunctionSkipName()` — FUNCDEF short names → scoped names for stub/M3 gates
- `m3CompileDriverSpineDenyNames()` — documented LLVM 9 crashers during spine expansion
- `compileBlockPhpLowering()` + `compileRuntime*M3Native()` — PHP CFG lowering for split `Runtime` ctor spine (#1494)

## Step 1 (done — #1402)

| Allowlist | Gate |
|-----------|------|
| `helloworld_compile_smoke` only | `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` link OK (no segfault) |

## Step 2 (in progress)

| Allowlist / native spine | Gate |
|--------------------------|------|
| `php_compiler_cli_dispatch` / `php_compiler_cli_should_run_entry_driver` | On M3 allowlist; CLI driver dispatch without Zend |
| `Runtime::parseAndCompile` / `parseAndCompileEmitSmoke` | On M3 allowlist when `PHP_COMPILER_M3_COMPILE_DRIVER=1` |
| `Runtime::parse` / `Runtime::compile` / `compileEmitSmoke` | On M3 allowlist; compile-driver link OK (#1496) |
| `Runtime::prepareSourceForParser` / `preprocessSourceForParse` / `rewriteSourceBeforeParser` | On M3 allowlist; parse spine real-lowers with compile driver (#1496, #8706) |
| `Runtime::parse` / `prepareSourceForParser` stub | **Retired** on executable argv drivers when `PHP_COMPILER_EMIT_HELPER_LINK=1` + inventory/M4 argv gates — `shouldStubInventoryEmitParseCompileSpine()` false (#8706); mirrors `standalone` prelower gate |
| `Runtime::loadJitContext` | PHP CFG via `compileRuntimeLoadJitContextM3Native` (separate FUNCDEF from `loadJit` — #2846) |
| `Runtime::__construct` | Slim ctor via `compileRuntimeConstructM3Native` → `compileBlockPhpLowering` (#1494) |
| `Runtime::__destruct` | Void no-op via `compileRuntimeDestructM3Native` (#2867) |
| `Runtime::initParsePipeline` / `Runtime::loadCoreModules` | `compileRuntime*M3Native` → PHP CFG lowering (#1494) |
| `Runtime::initCompiler` | **Native** via `RuntimeInitCompiler::emit` on M3 compile-driver + emit TU (#2568); not PHP CFG |
| `Runtime::initVmContext` | **Native** via `RuntimeInitVmContext::emit` (allocate `VM\Context` + `ErrorReporter` + `ScriptStack`, wire `runtime` + `vmContext`); wired in `compileBlock()`; off deny list (#1494, #2126). PHP CFG `new VMContext` still LLVM 9 link crash when combined with ctor spine. |
| `Runtime::loadJit` | `compileRuntimeLoadJitM3Native` — outer orchestration (#1495) |
| `Runtime::createJit` / `jitContextForLoadJit` / `loadJitCompileModuleFuncs` | Separate FUNCDEFs via `compileRuntime*M3Native` → PHP CFG (#2847) |
| `Runtime::noteParseCompileNullForScript` / `peekLastParseFailure` | On M3 allowlist; compile-driver diagnostics + last failure peek |
| `Block::slotIndexForVariableName` / `Block::slotForOperand` | PHP CFG via `isM3CompileDriverBlockPhpLoweringName` (#2848) |
| `Compiler::compileFunc` | On M3 allowlist; CFG entry real-lowers on compile-driver link (#9228, #1402) |
| `Runtime::standalone` | Compile-driver link OK (#1402, #1056) |
| `helloworld_compile_smoke` | Deny-listed for link (LLVM 9); compile_driver bundle keeps stub; runtime emit via `compiler_helloworld_smoke/compile_driver.php` / `compiler_compile_smoke/compile_driver.php` + `PHP_COMPILER_EMIT_HELPER_LINK=1` (#1768, #1983) |
| Native emit runtime | `BOOTSTRAP_M3_RUNTIME_COMPILE=1` + `PHP_COMPILER_M3_EMIT_MINIMAL=1` skips eager `loadJitCompileModuleFuncs` during smoke emit |
| `runtime_ctor_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_ctor_smoke.php`; int exit (#1514) |
| `runtime_parse_compile_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_parse_compile_smoke.php` |

**Runtime emit:** compile driver uses env dispatch (`PHP_COMPILER_M3_COMPILE_MODE=compile`, `PHP_COMPILER_M3_SOURCE`, `PHP_COMPILER_M3_OUT`) so AOT entry avoids top-level `__DIR__` concat (#1493). Probe with `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` links `compile_driver.php` with `PHP_COMPILER_M3_COMPILE_DRIVER=1` (not the separate emit-helper TU). With `BOOTSTRAP_M3_RUNTIME_COMPILE=1`, compile-driver **link** is OK; runtime still returns stubbed `helloworld_compile_smoke` (no `compile OK` stdout) until LLVM 9 real-lowering is safe. Separate `compiler_helloworld_smoke/compile_driver.php` + `PHP_COMPILER_EMIT_HELPER_LINK=1` links intermittently but the emit binary segfaults in `internal_1` / `__string__separate` at startup (#1514).

**Probe findings (2026-05):**

- **Link + real lowering:** `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1` + `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` → `compile driver link OK` (includes native `Runtime::initVmContext` on allowlist).
- **Runtime:** `BOOTSTRAP_M3_RUNTIME_COMPILE=1` — probe may exit 0 with Zend partial fallback; `helloworld_compile_smoke: compile OK` not yet reliable (native ctor / `VM\Context` incomplete).
- **Native success:** probe sets `M3_NATIVE_COMPILE=1` and `emit_path=native` only when compile driver exits 0, stdout contains `helloworld_compile_smoke: compile OK`, and `build/helloworld-aot` is executable.
- **Strict:** `BOOTSTRAP_M3_HELLOWORLD_STRICT=1` fails with `emit_path=zend_fallback_would_be_used block_reason=…` (no Zend `bin/compile.php` fallback).

Re-run link gate:

```bash
BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
./script/bootstrap-selfhost-helloworld-probe.sh
```

Runtime gate:

```bash
BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
./script/bootstrap-selfhost-helloworld-probe.sh
```

Strict native emit (no Zend fallback):

```bash
BOOTSTRAP_M3_HELLOWORLD_STRICT=1 \
BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
BOOTSTRAP_M3_RUNTIME_COMPILE=1 \
./script/bootstrap-selfhost-helloworld-probe.sh
```

**M5 driver surface (#2681):** use the linked **helloworld compile driver** (`build/selfhost-helloworld-compile`), not `build/selfhost-helloworld-emit` (emit-TU logs `compile_smoke_m3_emit:` and mis-triages `bin/compile.php` failures).

```bash
make bootstrap-selfhost-helloworld   # also links build/selfhost-helloworld-compile
# HelloWorld:
make bootstrap-selfhost-helloworld-compile-bin
# bin/compile.php (honest helloworld_compile_smoke: prefix; may still fail at parseAndCompile until #2633):
PHP_COMPILER_M3_SOURCE=bin/compile.php PHP_COMPILER_M3_OUT=/tmp/bin-compile-aot \
  make bootstrap-selfhost-helloworld-compile-bin
```

### Known LLVM 9 link crashers (deny list)

| Symbol | Notes |
|--------|-------|
| `helloworld_compile_smoke` | Deny-listed FUNCDEF (#1514); emit via M3 sidecar |

**Next:** complete native `VM\Context` (hashtable props + sub-objects) without LLVM 9 link regression, or small `lib/AOT/runtime/` C floor (#1494).

## Env flags

| Flag | When |
|------|------|
| `PHP_COMPILER_SELFHOST_AOT=1` | Self-host bundle link |
| `PHP_COMPILER_M3_COMPILE_DRIVER=1` | Real lowering for allowlisted M3 spine |
| `PHP_COMPILER_M3_RUNTIME_COMPILE=1` | Run native compile in linked driver (runtime) |
| `PHP_COMPILER_M3_COMPILE_MODE=compile` | Compile-driver dispatch (with `PHP_COMPILER_M3_SOURCE` / `PHP_COMPILER_M3_OUT`) |
| `BOOTSTRAP_M3_HELLOWORLD_STRICT=1` | Fail probe without Zend emit fallback |

---

## Full M5 ladder (PHP-first, small C floor)

| Step | What moves into PHP | Zend / vendor |
|------|---------------------|---------------|
| **M3 close** | Native emit via compiled `Runtime` / `Compiler` / `JIT` | Zend emit retired |
| **M4** | Native binary rebuilds next compiler revision | Zend cold-boot only |
| **M5** | Full `lib/` + `ext/` inventory in bundle | **No vendor autoload** — prelink [#1416](https://github.com/PurHur/php-compiler/issues/1416) |

**C runtime** (`lib/AOT/runtime/*.c`): stays small — only `__compiler_*` symbols for AOT stdlib + libc/PCRE. Do not move compiler logic into C.

**Vendor inventory:** `php script/bootstrap-vendor-inventory.php` → [`docs/bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md).

**Vendor prelink (#1416):** literal-require bundles + manifest (committed); AOT objects when LLVM vendor JIT is stable:

```bash
make bootstrap-vendor-prelink-bundles   # test/bootstrap-vendor-prelink/generated/*_bundle.php + prelinked/bootstrap-vendor/manifest.json
make bootstrap-vendor-objects           # also AOT → prelinked/bootstrap-vendor/*.o (PHP_COMPILER_KEEP_OBJECT_FILE=1)
```

`bootstrap-vendor-objects.php --compile` sets `PHP_COMPILER_VENDOR_PRELINK=1` so `lib/JIT.php` skips non-jittable vendor class bodies during link-only AOT; `bootstrapVendorPrelinkResolveCompileInvoker()` prefers a gen-0 native driver under `build/` when present ([#2842](https://github.com/PurHur/php-compiler/issues/2842), [#2849](https://github.com/PurHur/php-compiler/issues/2849)) and skips `vendor/autoload.php` via `src/cli.php`. **May 2026:** all three packages (`php-cfg`, `php-types`, `php-llvm`) → `object_ok` under Zend or native gen-0 driver when `vendor/` sources are present.

**Cold boot (no `vendor/`):** committed bundles + `prelinked/bootstrap-vendor/*.o` only ([#2841](https://github.com/PurHur/php-compiler/issues/2841)):

```bash
php script/bootstrap-vendor-objects.php --check    # validates committed bundles (no composer tree)
php script/bootstrap-vendor-objects.php --compile  # reuses committed .o when vendor/ absent
```

Rebuild of vendor `.o` uses literal `vendor/{package}` sources (bundle `require_once` paths) via Zend or a gen-0 native driver under `build/` ([#2842](https://github.com/PurHur/php-compiler/issues/2842), [#2849](https://github.com/PurHur/php-compiler/issues/2849)). With `vendor/` absent, `--compile` reuses committed `prelinked/bootstrap-vendor/*.o` when present ([#2841](https://github.com/PurHur/php-compiler/issues/2841)); missing `.o` rebuild from `prelinked/bootstrap-vendor/sources/` + symlinked `vendor/ircmaxell/*` (no autoload — [#2881](https://github.com/PurHur/php-compiler/issues/2881)).

**Cold-boot native→Zend fallback (#2967):** the gen-0 native driver (`build/selfhost-native-compile-driver`) currently returns `parseAndCompile returned null (parser/CFG spine)` for the three vendor bundles ([#3028](https://github.com/PurHur/php-compiler/issues/3028) php-cfg, [#3030](https://github.com/PurHur/php-compiler/issues/3030) php-types, [#3031](https://github.com/PurHur/php-compiler/issues/3031) php-llvm). When `bootstrapVendorPrelinkColdBootCompile()` detects that **all** rebuild failures are this native parse-spine null (`bootstrapVendorPrelinkFailuresAreNativeParseSpine()`), it retries the rebuild once with the Zend `bin/compile.php` invoker (`BOOTSTRAP_M5_VENDOR_ALLOW_ZEND=1`). This keeps `north-star5-verify` **step 5b** green (3/3 `object_ok`) while the native vendor-bundle emit gap is closed — i.e. the M5 ladder no longer regresses on the native parser/CFG spine, but cold boot is still **Zend-assisted** until [#3028](https://github.com/PurHur/php-compiler/issues/3028)/[#3030](https://github.com/PurHur/php-compiler/issues/3030)/[#3031](https://github.com/PurHur/php-compiler/issues/3031) land native rebuild.

**Fresh-clone prerequisite:** `script/apply-patches.sh` must apply cleanly. The `php-types-magic-script-const` and `php-types-first-class-callable` overlay anchors include the `case 'Expr_YieldFrom':` line inserted by `php-types-yield-from.patch`; the overlays run after yield-from, so their anchors must match the post-yield-from `TypeReconstructor.php` (otherwise `set -e` aborts the whole patch run on a fresh `composer install`).

Presenter: **`make north-star5-verify-fast`** (default) / `./script/north-star5-verify.sh --fast` ([#1492](https://github.com/PurHur/php-compiler/issues/1492)). Full ladder: `make north-star5-verify ARGS=--strict` ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

**Vendor-absent wave-check in full CI:** `./script/ci-local.sh` ends the LLVM phase with `./script/bootstrap-wave-check.sh --vendor-absent --fail-fast` (default-on `BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE=1`, issue [#8712](https://github.com/PurHur/php-compiler/issues/8712)). Same lib-spine cold-boot slice as north-star5 step 4c; set `BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT=0` to skip on dev machines without committed prelinked `.o`.

`lib/AOT/Linker.php::prelinkedVendorObjectPaths()` reads `object_ok` entries from the manifest. CI: `BOOTSTRAP_VENDOR_PRELINK_SYNC_GATE=1` (bundles); `BOOTSTRAP_VENDOR_PRELINK_GATE=1` for compile probe in wave-check (opt-in).

**Stub policy:** shrink `PHP_COMPILER_SELFHOST_AOT` stubs on the **compile spine first** (`parseAndCompile` → `standalone` → `Compiler::compile`), not whole-tree at once.

**Related:** [self-host-target.md](self-host-target.md) · [bootstrap-selfhost.md](bootstrap-selfhost.md) · [#1056](https://github.com/PurHur/php-compiler/issues/1056) · vendor prelink [#1416](https://github.com/PurHur/php-compiler/issues/1416)

---

## Fast feedback loops (M5 green, Jun 2026)

Use these during LLVM/JIT iteration — avoid full spine relink unless you changed `compiler_lib_spine_smoke/main.php` or need freshness.

| Command | Typical time | Notes |
|---------|--------------|-------|
| `make bootstrap-selfhost-helloworld` | **~30s–3 min** | M3 gate — prelinked emit-helper + HelloWorld sidecar when inventory `--check` green ([#9704](https://github.com/PurHur/php-compiler/issues/9704)); cold LLVM link opt-in `BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK=1` |
| `make bootstrap-selfhost-vm-driver-execute-probe` | **~20ms** | Native env gate only; seeds from `prelinked/bootstrap-gen0` if binary missing |
| `make north-star5-verify-fast` | **~1–2 min** | PR M5 presenter — inventory + spine + prelinked blobs + VM probe (no relink) |
| `make north-star5-verify --strict` | **~1h** | Full M5 ladder before merging bootstrap/M5 work (not every PR) |
| `make bootstrap-selfhost-lib-spine-smoke` | minutes | Full spine link — run after spine entry edits or before refreshing gen-0 blobs |
| `make bootstrap-warm-m3-sidecars` | seconds–minutes | Pre-build independent M3 sidecars (parallel when `PHP_COMPILER_COMPILE_JOBS>1`) before slow emit/link |
| `make bootstrap-gen0-refresh-sidecar` | minutes | Full spine link + copy `build/.m3_*` → `prelinked/bootstrap-gen0/` + manifest refresh ([#8704](https://github.com/PurHur/php-compiler/issues/8704)) |
| `php script/bootstrap-inventory.php --check` | seconds | Inventory SSOT without LLVM |
| `php script/check-selfhost-spine-coverage-sync.php` | seconds | Spine ↔ inventory coverage (**2869/2848**) |
| `php script/check-selfhost-spine-sidecar-sync.php` | seconds | Prelinked gen-0 stamp ↔ spine entry SHA-1 ([#8703](https://github.com/PurHur/php-compiler/issues/8703)) |

**Env flags**

| Flag | When |
|------|------|
| `BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1` | VM probe must rebuild spine (post-entry edit, refresh gen-0) |
| `BOOTSTRAP_ALLOW_STALE_SIDECAR=1` | Waive `check-selfhost-spine-sidecar-sync.php` during intentional gen-0 blob batch PRs only ([#8703](https://github.com/PurHur/php-compiler/issues/8703)) |
| `BOOTSTRAP_INVENTORY_COMPILED_FIRST=1` | **Default-on** — inventory argv link tries native gen-0 drivers before Zend (#3053) |
| `BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS=1` | **Default-on** in `ci-defaults.env` — inventory argv link skips 2787-file spine sidecar host-compile (~20s) |
| `BOOTSTRAP_INVENTORY_DRIVER_FULL=1` | Opt-in M4 full-revision inventory link (all 16 sidecars; slow) |
| `BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN=1` | Force honest full-spine sidecar host-compile (multi-hour) |
| `BOOTSTRAP_M5_NO_ZEND=1` | Cold boot from prelinked gen-0 only ([#3053](https://github.com/PurHur/php-compiler/issues/3053)) |
| `BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK=1` | M3 helloworld probe: force cold Zend LLVM inventory `compile_driver` link (default skips when `prelinked/bootstrap-gen0/.m3_compile_driver_aot_blob` valid — [#9704](https://github.com/PurHur/php-compiler/issues/9704)) |
| `PHP_COMPILER_EMIT_HELPER_LINK=1` + `PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1` | Inventory argv emit-helper link; parse/CFG spine **real-lowers** (not stubbed) when `PHP_COMPILER_SELFHOST_AOT=1` or vendor-prelink executable emit ([#8706](https://github.com/PurHur/php-compiler/issues/8706)) |
| `PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1` | M4 `bin/compile.php` argv driver; parse spine real-lowers with emit-helper link ([#8706](https://github.com/PurHur/php-compiler/issues/8706)) |
| `PHP_COMPILER_COMPILE_JOBS=N` | Fan out independent `bin/compile.php` subprocesses for vendor prelink (`make bootstrap-vendor-objects`) and optional sidecar warmup (`make bootstrap-warm-m3-sidecars`). Default `1`. RAM ≈ jobs × `PHP_COMPILER_MEMORY_LIMIT`. |
