# M5 fast path — incremental native emit (M3 compile driver)

Issue [#1056](https://github.com/PurHur/php-compiler/issues/1056); link segfault fix [#1402](https://github.com/PurHur/php-compiler/issues/1402).

## Goal

Self-host HelloWorld bundle emits AOT without Zend `bin/compile.php` fallback (`BOOTSTRAP_M3_HELLOWORLD_STRICT=1`).

## Tactic

Expand `JIT::isM3CompileDriverRealLoweringName()` **one function at a time** while `PHP_COMPILER_M3_COMPILE_DRIVER=1` links `test/selfhost/compiler_helloworld_smoke/compile_driver.php`.

Supporting fixes from #1402:

- `jitFunctionSkipName()` — FUNCDEF short names → scoped names for stub/M3 gates
- `isSkippedCompilerHotPathName()` — always stub `Block::slotIndexForVariableName`
- `m3CompileDriverSpineDenyNames()` — documented LLVM 9 crashers during spine expansion

## Step 1 (done — #1402)

| Allowlist | Gate |
|-----------|------|
| `helloworld_compile_smoke` only | `BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1` link OK (no segfault) |

## Step 2 (in progress)

| Allowlist | Gate |
|-----------|------|
| `Runtime::parseAndCompile` | Link OK with `PHP_COMPILER_M3_COMPILE_DRIVER=1` |
| `Runtime::loadJitContext` | Compile-driver link OK (#1402); `loadJit` still denied (LLVM 9 segfault) |

Runtime emit still blocked: ctor / `loadJit` / `standalone` remain stubbed until removed from deny list.

Re-run link gate:

```bash
BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 \
BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 \
./script/bootstrap-selfhost-helloworld-probe.sh
```

Opt-in runtime (after spine symbols are real-lowered):

```bash
BOOTSTRAP_M3_RUNTIME_COMPILE=1  # separate from link; do not enable until spine is ready
```

### Known LLVM 9 link crashers (deny list)

| Symbol | Notes |
|--------|-------|
| `Block::slotIndexForVariableName` | Also in compiler hot-path skip |
| `Runtime::__construct` | LLVM 9 segfault during compile-driver link |
| `Runtime::loadJit` | LLVM 9 segfault (even when `loadJitContext` is real-lowered) |
| `Runtime::standalone` | Module verify: ICmp operand type mismatch (enable only after `loadJit`) |

## Env flags

| Flag | When |
|------|------|
| `PHP_COMPILER_SELFHOST_AOT=1` | Self-host bundle link |
| `PHP_COMPILER_M3_COMPILE_DRIVER=1` | Real lowering for allowlisted M3 spine |
| `PHP_COMPILER_M3_RUNTIME_COMPILE=1` | Run native compile in linked driver (runtime) |
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

**Related:** [`docs/bootstrap-selfhost.md`](bootstrap-selfhost.md) · [#1056](https://github.com/PurHur/php-compiler/issues/1056)
