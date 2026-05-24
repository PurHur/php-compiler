# M5 fast path — incremental native emit (M3 compile driver)

**North Star 2 target (full ladder):** [self-host-target.md](self-host-target.md)

Issue [#1056](https://github.com/PurHur/php-compiler/issues/1056); link segfault fix [#1402](https://github.com/PurHur/php-compiler/issues/1402).

## Goal

Self-host HelloWorld bundle emits AOT without Zend `bin/compile.php` fallback (`BOOTSTRAP_M3_HELLOWORLD_STRICT=1`).

## Tactic

Expand `JIT::isM3CompileDriverRealLoweringName()` **one function at a time** while `PHP_COMPILER_M3_COMPILE_DRIVER=1` links `test/selfhost/compiler_helloworld_smoke/compile_driver.php`.

Supporting fixes from #1402:

- `jitFunctionSkipName()` — FUNCDEF short names → scoped names for stub/M3 gates
- `isSkippedCompilerHotPathName()` — always stub `Block::slotIndexForVariableName`
- `m3CompileDriverSpineDenyNames()` — documented LLVM 9 crashers during spine expansion
- `compileBlockPhpLowering()` + `compileRuntime*M3Native()` — PHP CFG lowering for split `Runtime` ctor spine (#1494)

## Step 1 (done — #1402)

| Allowlist | Gate |
|-----------|------|
| `helloworld_compile_smoke` only | `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` link OK (no segfault) |

## Step 2 (in progress)

| Allowlist / native spine | Gate |
|--------------------------|------|
| `Runtime::parseAndCompile` | On M3 allowlist when `PHP_COMPILER_M3_COMPILE_DRIVER=1` |
| `Runtime::parse` / `Runtime::compile` | On M3 allowlist; compile-driver link OK (#1496) |
| `Runtime::loadJitContext` | Compile-driver link OK (#1402) |
| `Runtime::__construct` | Slim ctor via `compileRuntimeConstructM3Native` → `compileBlockPhpLowering` (#1494) |
| `Runtime::initParsePipeline` / `Runtime::initCompiler` / `Runtime::loadCoreModules` | Real-lowered via `compileRuntime*M3Native` when not combined with `initVmContext` |
| `Runtime::initVmContext` | **LLVM 9 link segfault** when real-lowered alongside other spine helpers; stays on deny list (stub at runtime) |
| `Runtime::loadJit` | `compileRuntimeLoadJitM3Native` + nested `createJit` helpers (#1495) |
| `Runtime::standalone` | Compile-driver link OK (#1402, #1056) |
| `helloworld_compile_smoke` | Link OK with real lowering |
| `runtime_ctor_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_ctor_smoke.php` |
| `runtime_parse_compile_smoke` | `php bin/compile.php -l test/bootstrap-aot/runtime_parse_compile_smoke.php` |

**Runtime emit:** compile driver uses env dispatch (`PHP_COMPILER_M3_COMPILE_MODE=compile`, `PHP_COMPILER_M3_SOURCE`, `PHP_COMPILER_M3_OUT`) so AOT entry avoids top-level `__DIR__` concat (#1493). `BOOTSTRAP_M3_RUNTIME_COMPILE=1` reaches `helloworld_compile_smoke` but still segfaults — stubbed `Runtime::initVmContext` leaves `vmContext` uninitialized. Real-lowering `initVmContext` segfaults LLVM 9 at link when combined with ctor spine. Next: safe `VMContext` C floor or isolated `initVmContext` link.

**Probe findings (2026-05):**

- **Link + real lowering:** `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1` + `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` → `compile driver link OK` (includes `Runtime::parse` / `Runtime::compile` on allowlist, not on deny list — #1496).
- **Runtime:** env dispatch enters compile mode (#1493); `BOOTSTRAP_M3_RUNTIME_COMPILE=1` still segfaults until `initVmContext` is real-lowered without LLVM 9 crash.
- **Native success:** probe sets `M3_NATIVE_COMPILE=1` and `emit_path=native` only when compile driver exits 0, stdout contains `helloworld_compile_smoke: compile OK`, and `build/helloworld-aot` is executable.
- **Strict:** `BOOTSTRAP_M3_HELLOWORLD_STRICT=1` fails with `emit_path=zend_fallback_would_be_used block_reason=…` (no Zend `bin/compile.php` fallback).

Re-run link gate:

```bash
BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
./script/bootstrap-selfhost-helloworld-probe.sh
```

Runtime gate (blocked on `initVmContext`):

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

### Known LLVM 9 link crashers (deny list)

| Symbol | Notes |
|--------|-------|
| `Block::slotIndexForVariableName` | Also in compiler hot-path skip |
| `Runtime::initVmContext` | `new VMContext` segfaults when lowered with full ctor spine (#1494) |
| `Runtime::createJit` / `jitContextForLoadJit` / `loadJitCompileModuleFuncs` | Split from `loadJit`; stay on deny list (stubbed) while outer `loadJit` is real-lowered (#1495) |

**Dependency:** runtime `loadJit` needs real `initVmContext` (`fix/m3-init-vmcontext`); until then `BOOTSTRAP_M3_RUNTIME_COMPILE=1` may segfault in ctor.

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

**Stub policy:** shrink `PHP_COMPILER_SELFHOST_AOT` stubs on the **compile spine first** (`parseAndCompile` → `standalone` → `Compiler::compile`), not whole-tree at once.

**Related:** [self-host-target.md](self-host-target.md) · [bootstrap-selfhost.md](bootstrap-selfhost.md) · [#1056](https://github.com/PurHur/php-compiler/issues/1056) · vendor prelink [#1416](https://github.com/PurHur/php-compiler/issues/1416)
