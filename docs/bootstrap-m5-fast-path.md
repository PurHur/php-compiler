# M5 fast path — incremental native emit (M3 compile driver)

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
| `Compiler::unwrapOperandChain` / `operandsChainEqual` | Native via `JIT\CompilerOperandChainNative` (PHP CFG hits LLVM 9 dominance verify — #1768); `make bootstrap-selfhost-compile-driver-link` |
| `Runtime::parseAndCompile` | On M3 allowlist when `PHP_COMPILER_M3_COMPILE_DRIVER=1` |
| `Runtime::parse` / `Runtime::compile` | On M3 allowlist; compile-driver link OK (#1496) |
| `Runtime::loadJitContext` | PHP CFG via `compileRuntimeLoadJitContextM3Native` (separate FUNCDEF from `loadJit` — #2846) |
| `Runtime::__construct` | Slim ctor via `compileRuntimeConstructM3Native` → `compileBlockPhpLowering` (#1494) |
| `Runtime::initParsePipeline` / `Runtime::loadCoreModules` | `compileRuntime*M3Native` → PHP CFG lowering (#1494) |
| `Runtime::initCompiler` | **Native** via `RuntimeInitCompiler::emit` on M3 compile-driver + emit TU (#2568); not PHP CFG |
| `Runtime::initVmContext` | **Native** via `RuntimeInitVmContext::emit` (allocate `VM\Context` + `ErrorReporter` + `ScriptStack`, wire `runtime` + `vmContext`); wired in `compileBlock()`; off deny list (#1494, #2126). PHP CFG `new VMContext` still LLVM 9 link crash when combined with ctor spine. |
| `Runtime::loadJit` | `compileRuntimeLoadJitM3Native` — outer orchestration (#1495) |
| `Runtime::createJit` / `jitContextForLoadJit` / `loadJitCompileModuleFuncs` | Separate FUNCDEFs via `compileRuntime*M3Native` → PHP CFG (#2847) |
| `Block::slotIndexForVariableName` | PHP CFG via `isM3CompileDriverBlockPhpLoweringName` (#2848) |
| `Runtime::standalone` | Compile-driver link OK (#1402, #1056) |
| `helloworld_compile_smoke` | Deny-listed for link (LLVM 9); compile_driver bundle keeps stub; runtime emit via `helloworld_m3_emit_native_entry.php` / `compile_smoke_m3_emit_native_entry.php` + `PHP_COMPILER_EMIT_HELPER_LINK=1` (#1768, #1983) |
| Native emit runtime | `BOOTSTRAP_M3_RUNTIME_COMPILE=1` + `PHP_COMPILER_M3_EMIT_MINIMAL=1` skips eager `loadJitCompileModuleFuncs` during smoke emit |
| `runtime_ctor_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_ctor_smoke.php`; int exit (#1514) |
| `runtime_parse_compile_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_parse_compile_smoke.php` |

**Runtime emit:** compile driver uses env dispatch (`PHP_COMPILER_M3_COMPILE_MODE=compile`, `PHP_COMPILER_M3_SOURCE`, `PHP_COMPILER_M3_OUT`) so AOT entry avoids top-level `__DIR__` concat (#1493). Probe with `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` links `compile_driver.php` with `PHP_COMPILER_M3_COMPILE_DRIVER=1` (not the separate emit-helper TU). With `BOOTSTRAP_M3_RUNTIME_COMPILE=1`, compile-driver **link** is OK; runtime still returns stubbed `helloworld_compile_smoke` (no `compile OK` stdout) until LLVM 9 real-lowering is safe. Separate `helloworld_m3_emit_native_entry.php` + `PHP_COMPILER_EMIT_HELPER_LINK=1` links intermittently but the emit binary segfaults in `internal_1` / `__string__separate` at startup (#1514).

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
| `Runtime::__destruct` | Deny-listed (LLVM 9; not on compile spine) |
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

`bootstrap-vendor-objects.php --compile` sets `PHP_COMPILER_VENDOR_PRELINK=1` so `lib/JIT.php` skips non-jittable vendor class bodies during link-only AOT. **May 2026:** `ircmaxell/php-types` → `object_ok`; `php-cfg` / `php-llvm` still blocked on parse/type reconstruction in bundled compile.

Presenter: `make north-star5-verify` / `./script/north-star5-verify.sh` ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

`lib/AOT/Linker.php::prelinkedVendorObjectPaths()` reads `object_ok` entries from the manifest. CI: `BOOTSTRAP_VENDOR_PRELINK_SYNC_GATE=1` (bundles); `BOOTSTRAP_VENDOR_PRELINK_GATE=1` for compile probe in wave-check (opt-in).

**Stub policy:** shrink `PHP_COMPILER_SELFHOST_AOT` stubs on the **compile spine first** (`parseAndCompile` → `standalone` → `Compiler::compile`), not whole-tree at once.

**Related:** [self-host-target.md](self-host-target.md) · [bootstrap-selfhost.md](bootstrap-selfhost.md) · [#1056](https://github.com/PurHur/php-compiler/issues/1056) · vendor prelink [#1416](https://github.com/PurHur/php-compiler/issues/1416)
