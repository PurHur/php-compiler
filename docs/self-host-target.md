# Self-host target — compiler compiles itself

**Project north star (living tracker):** [#1492](https://github.com/PurHur/php-compiler/issues/1492) (was [#1056](https://github.com/PurHur/php-compiler/issues/1056))  
**Public status:** [development-status § North star](https://purhur.github.io/php-compiler/development-status.html#north-star-self-host)  
**M2 batch tracker:** [#1419](https://github.com/PurHur/php-compiler/issues/1419) (closed — work landed in PRs)

---

## The target (one sentence)

**A native binary built from this repo’s PHP (`lib/` + `ext/`) can compile PHP—including the next revision of the compiler—without Zend PHP in the loop.**

That is **M5**. Everything below is the honest path from today’s bootstrap to that outcome.

---

## Definition of done (M5)

| Requirement | Meaning |
|-------------|---------|
| **Compiler in PHP** | Front/middle/back end stay in `lib/`, `ext/` — not rewritten in C |
| **No `vendor/` at cold boot** | Parser/types/LLVM FFI prelinked once ([#1416](https://github.com/PurHur/php-compiler/issues/1416)); see [`bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md) |
| **No Zend bootstrap** | `bin/compile.php` / `bin/vm.php` run as **compiled** code, not `php bin/compile.php` |
| **Full inventory** | Honest bundle covers the `bin/vm.php` path (~**611** files; [`bootstrap-inventory.md`](bootstrap-inventory.md)) |
| **Stub surface minimal** | `PHP_COMPILER_SELFHOST_AOT` stubs shrink; compiler behavior is real, not link-only |
| **Small native floor OK** | `lib/AOT/runtime/*.c` + external `clang` via `lib/AOT/Linker.php` (in spine smoke native link since [#2267](https://github.com/PurHur/php-compiler/issues/2267)) — **not** required to disappear |

**Not required for M5:** 100% Zend parity · in-process linker · production web-app polish (examples stay as regression fixtures).

---

## Where we are (May 2026, verified on `master`)

| Layer | Today | Target |
|-------|-------|--------|
| **Bootstrap driver** | Gen-0 Zend `php bin/compile.php` once; routine `bootstrap-selfhost-link` / spine / M4 gen-1 prefer `build/bin-compile-aot` or `build/selfhost-compile-driver` when present ([#2842](https://github.com/PurHur/php-compiler/issues/2842)) | Compiled `bin/compile.php` only |
| **Bundle size** | **725** curated `require_once` in spine smoke | **725** Phase A inventory files (SSOT) |
| **Inventory coverage** | **725/725** in spine smoke with real **`bin/vm.php`** ([#2134](https://github.com/PurHur/php-compiler/issues/2134); **#2652** emit-TU JIT batch; **`lib/VM/HashTable.php`** promoted [#2543](https://github.com/PurHur/php-compiler/issues/2543)); `src/cli_driver.php` via `cli_spine_shim` | **~100%** |
| **HelloWorld** | Native **run** ✅; strict probe **emit_path=native** ✅ (27 May 2026) | Native compile for arbitrary PHP |
| **Bootstrap loop (M4)** | Gen-1→gen-2 smoke/spine **native** ✅; gen-2→gen-3 spine **725/725** ✅; full revision / argv CLI ⬜ | Native full revision rebuild |
| **Vendor** | `ircmaxell/php-types` **object_ok** ([#1416](https://github.com/PurHur/php-compiler/issues/1416)); cfg/llvm parse blockers | Prelinked artifacts only (no `vendor/autoload.php`) |

### Gates (run after `script/apply-patches.sh`)

| Gate | Status |
|------|--------|
| M0 `bootstrap-selfhost-link.sh` | ✅ `compiler_minimal bundle OK` |
| M1 compile-smoke AOT echo | ✅ `compiler smoke` |
| M3 compile-smoke strict | ✅ `make bootstrap-selfhost-compile-smoke-strict` → `emit_path=native` (#2610) |
| M3 Runtime compile smoke strict | ✅ `make bootstrap-selfhost-runtime-compile-smoke-strict` → `emit_path=native` (#2610) |
| M3 compiler-unit strict | ✅ `make bootstrap-selfhost-compiler-unit-probe-strict` → `emit_path=native` (#2618) |
| M2 `BOOTSTRAP_LIB_SPINE_SMOKE=1` spine link | ✅ `compiler_lib_spine_smoke bundle OK` |
| M3 `make bootstrap-selfhost-helloworld` | ✅ strict default — HelloWorld `emit_path=native` (verified 27 May 2026) |
| M4 `make bootstrap-loop-gen1-link` | ✅ gen-1 link + gen-2 smoke **`emit_path=native`** (`BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1`, #2611); **Zend fallback** (`emit_path=zend partial`) when native blocked; strict: `BOOTSTRAP_M4_GEN2_STRICT=1`; presenter: [GETTING-STARTED §7](GETTING-STARTED.md) ([#2464](https://github.com/PurHur/php-compiler/issues/2464)) |
| M4 `make bootstrap-loop-gen1-full-spine-emit` | ✅ gen-1→gen-2 **725/725** spine (`bootstrap-loop-gen2-full-spine`) |
| M4 `make bootstrap-loop-gen2-recompile-spine` | ✅ gen-2→gen-3 **725/725** spine (`bootstrap-loop-gen3-full-spine`) — native driver recompiles spine ([#2697](https://github.com/PurHur/php-compiler/pull/2697)) |
| M4 `make bootstrap-loop-probe` | ✅ full ladder — M2 + M3 HelloWorld strict + gen-1→gen-2 + gen-2→gen-3; `--dry-run` skips gen-3 ([#1498](https://github.com/PurHur/php-compiler/issues/1498)); `make north-star4-verify` — [GETTING-STARTED §7](GETTING-STARTED.md) |
| `make bootstrap-aot-link` | ✅ **71/71** |
| `make bootstrap-inventory-check` | ✅ **725** Phase A files, **725** vm.php-path files, **0** source blockers |
| `make north-star5-verify` | ✅ inventory + spine + selfhost link; vendor **1/3** `object_ok` (partial; `--strict` for 3/3) |

---

## Milestone ladder

| Milestone | What it proves | Status | ~% |
|-----------|----------------|--------|-----|
| **M0** | AOT can link a **small** honest `lib/` subset | ✅ | 100% |
| **M1** | Bundle is **compiler-shaped** (lint + compile-smoke) | ✅ | 100% |
| **M2** | Spine grows toward full `bin/vm.php` inventory | ✅ **725** units (link; **`bin/vm.php`** [#2134](https://github.com/PurHur/php-compiler/issues/2134); [#2543](https://github.com/PurHur/php-compiler/issues/2543); [#2652](https://github.com/PurHur/php-compiler/issues/2652)) | **~100%** |
| **M3** | Self-host binary **compiles external PHP** (HelloWorld) without Zend emit | 🚧 smoke strict **native** ✅; full `Compiler` CFG 🚧 | **~65%** |

**M3 unit probe presenter** ([#2360](https://github.com/PurHur/php-compiler/issues/2360)): `make north-star3-verify` runs `008-SelfHostProbe` plus optional `bootstrap-selfhost-{compiler,jit,vm,parser,types}-unit-probe.sh` when LLVM 9 and probe scripts are present (parser step [#2418](https://github.com/PurHur/php-compiler/issues/2418), PHPTypes step [#2434](https://github.com/PurHur/php-compiler/issues/2434)). **Parser unit probe** ([#2409](https://github.com/PurHur/php-compiler/issues/2409)): `make bootstrap-selfhost-parser-unit-probe` — CFG front-end (`Runtime` + `lib/Compiler.php` bundle); CI gate ([#2417](https://github.com/PurHur/php-compiler/issues/2417)) opt-in in `ci-local.sh`. **PHPTypes unit probe** ([#2430](https://github.com/PurHur/php-compiler/issues/2430)): `make bootstrap-selfhost-types-unit-probe` — `lib/JIT.php` / `JIT\Builtin\Type` external-class constant seeds; Zend smoke for `Type::TYPE_*` + union/intersection `fromTypeDecl`; `BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=1` default-on in `ci-local.sh` ([#2433](https://github.com/PurHur/php-compiler/issues/2433), [#2436](https://github.com/PurHur/php-compiler/issues/2436)). **VM unit probe** ([#2354](https://github.com/PurHur/php-compiler/issues/2354)): `make bootstrap-selfhost-vm-unit-probe` / `BOOTSTRAP_VM_UNIT_PROBE_GATE=1` in `ci-local.sh` llvm tail. Compiler/JIT unit probes: [#2216](https://github.com/PurHur/php-compiler/issues/2216), [#2332](https://github.com/PurHur/php-compiler/issues/2332).
| **M4** | Self-host binary **rebuilds** the next compiler tree | 🚧 gen-2→gen-3 spine **725/725** ✅; full revision / argv CLI ⬜ | **~75%** |
| **M5** | Full self-host; Zend retired from loop | 🚧 `php-types` prelinked **object_ok**; cold boot open | **~15%** |

**Indicative north star composite:** **~70%** (weighted across M0–M5; see formula below).

### North star % (single formula)

| Indicator | Formula | May 2026 |
|-----------|---------|----------|
| **M2 spine progress** | `require_once` units in `compiler_lib_spine_smoke` ÷ Phase A inventory file count | **725 / 725** (`bin/vm.php` [#2134](https://github.com/PurHur/php-compiler/issues/2134); `lib/VM/HashTable.php` [#2543](https://github.com/PurHur/php-compiler/issues/2543); **#2652** emit-TU JIT batch) |
| **Public “Self-host” row** | Same M2 ratio until M3–M5 gates add weight ([`development-status.md`](pages/development-status.md)) | **~99%** |
| **M5 vendor prelink** | `object_ok` packages ÷ 3 | **1 / 3** (`php-types`) |
| **Composite (internal)** | Milestone weights in table above (M0–M1 = 100%, M2 = spine %, M3–M5 = gate %) | **~70%** |

Regenerate inventory: `make bootstrap-inventory-regenerate` (or `php script/bootstrap-inventory.php`) · spine count: `php script/bootstrap-spine-count.php` (or `grep -c '^require_once' test/selfhost/compiler_lib_spine_smoke/main.php`)

---

## Critical path (order of work)

Work that moves “more compiler logic into compiled PHP” fastest:

```text
1. M3 close — native emit (not just native run)
   └─ Incremental real lowering: Runtime::__construct → loadJit → standalone
   └─ Tracker: #1402, docs/bootstrap-m5-fast-path.md
   └─ Gate: BOOTSTRAP_M3_HELLOWORLD_STRICT=1

2. M2 finish — spine → full inventory (or honest closure)
   └─ Remaining lib/ + Jit* on vm.php path; defer only Linker.php
   └─ Optional: #1467 src/cli.php entry shim
   └─ Gate: BOOTSTRAP_LIB_SPINE_SMOKE=1 stays green

3. M4 — bootstrap loop
   └─ Native binary compiles same tree → second-generation binary → gen-3 spine
   └─ Scaffold: `test/selfhost/bootstrap_loop_smoke/` + `make bootstrap-loop-probe` ([#1498](https://github.com/PurHur/php-compiler/issues/1498))
   └─ Generation ladder: [`bootstrap-generations.md`](bootstrap-generations.md)

4. M5 — vendor prelink + stub retirement
   └─ #1416: no composer at cold boot
   └─ Shrink PHP_COMPILER_SELFHOST_AOT stubs on compile spine
```

**Do not optimize:** growing `lib/AOT/runtime/*.c` for compiler logic — that is the app floor, not the compiler.

---

## What we already did (M2 wave, May 2026)

Parallel batches ([#1419](https://github.com/PurHur/php-compiler/issues/1419), [#1497](https://github.com/PurHur/php-compiler/issues/1497)) grew the spine **179 → 408** units and closed deferred spine issues:

| Area | Highlights |
|------|------------|
| **lib/** | JIT helpers, Web project path, `SwitchDetector`, `ProjectGraph`, `PhpcRun`, 4× `src/*` shims |
| **ext/standard Jit*** | Filesystem, string, HTTP/preg, reflection batches — most missing `Jit*` leaves added |
| **Inventory wrappers** | Closed as Module-registered (no spine `require_once` unless lint requires) |
| **Fixes** | Self-host stubs for spine link (#1462, #1482); `parseAndCompile` on M3 allowlist (#1413–#1415) |

**Bundles:**

| Entry | Units | Role |
|-------|------:|------|
| `test/selfhost/compiler_minimal/main.php` | **108** | M0 core |
| `test/selfhost/compiler_lib_spine_smoke/main.php` | **717** | M2 complete (#2543 VM HashTable + #2652 emit-TU JIT batch) |
| `test/selfhost/compiler_helloworld_smoke/` | — | M3 probe + compile driver |
| `test/selfhost/bootstrap_loop_smoke/` | — | M4 scaffold (gen-1→gen-2→gen-3 loop; [#1498](https://github.com/PurHur/php-compiler/issues/1498)) |

---

## PHP vs C vs vendor (re-root)

| Component | Language | Role |
|-----------|----------|------|
| Parser, CFG, Compiler, JIT, VM, ext/ | **PHP** | The compiler — **this is what self-host must compile** |
| LLVM IR generation | **PHP** (`lib/JIT.php` + php-llvm FFI) | Codegen |
| AOT runtime symbols | **C** (`lib/AOT/runtime/*.c`) | Thin `__compiler_*` floor for linked binaries |
| Final link | **clang** (external) | Object → executable |
| php-cfg, php-types, php-llvm, parser | **vendor PHP** today → **prelinked** at M5 | Bootstrap dependency, not reimplemented in C |

---

## Issue map

| Topic | Issue |
|-------|-------|
| North star tracker | [#1492](https://github.com/PurHur/php-compiler/issues/1492) |
| M2 batch umbrella (done) | [#1419](https://github.com/PurHur/php-compiler/issues/1419) |
| M3 compile driver / LLVM | [#1402](https://github.com/PurHur/php-compiler/issues/1402) |
| M4 bootstrap loop scaffold | [#1498](https://github.com/PurHur/php-compiler/issues/1498) |
| Vendor prelink strategy | [#1416](https://github.com/PurHur/php-compiler/issues/1416) |
| `src/cli.php` entry (M4) | [#1467](https://github.com/PurHur/php-compiler/issues/1467) |
| Roadmap parent | [#78](https://github.com/PurHur/php-compiler/issues/78) |

---

## Verify (copy-paste)

```bash
script/apply-patches.sh
make bootstrap-wave-check
./script/bootstrap-selfhost-link.sh
make bootstrap-selfhost-compile-smoke-run
BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke
make bootstrap-selfhost-helloworld
make bootstrap-inventory-check
php script/bootstrap-vendor-inventory.php
```

M3 strict (fails until native emit):

```bash
BOOTSTRAP_M3_HELLOWORLD_STRICT=1 make bootstrap-selfhost-helloworld
```

M3 unit probe bundle ([#2360](https://github.com/PurHur/php-compiler/issues/2360)):

```bash
make north-star3-verify
# Docker: ./script/docker-exec.sh -- bash -lc 'make north-star3-verify'
```

M4 bootstrap-loop probe ([#1498](https://github.com/PurHur/php-compiler/issues/1498)):

```bash
# Prerequisites only (no gen-2→gen-3):
./script/bootstrap-loop-probe.sh --dry-run   # exit 0 when lint + M2 spine + M3 HelloWorld strict + gen-1 green (#2612)

# Full gate (gen-1→gen-2 native + gen-2→gen-3 spine):
make bootstrap-loop-probe
make bootstrap-loop-gen2-recompile-spine   # gen-2→gen-3 only
# Exit codes: 0=strict green | 1=hard failure | 2=LLVM skip or M4 gen-2/gen-3 blocked
```

See [`bootstrap-generations.md`](bootstrap-generations.md) for artifact names and env vars.

M4 strict loop presenter ([#2379](https://github.com/PurHur/php-compiler/issues/2379); copy-paste walkthrough: [GETTING-STARTED §7](GETTING-STARTED.md) ([#2464](https://github.com/PurHur/php-compiler/issues/2464))):

```bash
make north-star4-verify
./script/north-star4-verify.sh --dry-run-only   # partial M4 (probe --dry-run)
./script/north-star4-verify.sh --strict           # fail on M3 strict / probe exit 2
# Docker: ./script/docker-exec.sh -- bash -lc './script/north-star4-verify.sh --dry-run-only'
```

---

## Related docs

- [`bootstrap-generations.md`](bootstrap-generations.md) — gen-0…gen-3 ladder and artifacts
- [`bootstrap-selfhost.md`](bootstrap-selfhost.md) — gates, waves, stub policy
- [`bootstrap-m5-fast-path.md`](bootstrap-m5-fast-path.md) — M3 incremental lowering playbook
- [`bootstrap-inventory.md`](bootstrap-inventory.md) — per-file inventory
- [`pages/development-status.md`](pages/development-status.md) — public milestone table
