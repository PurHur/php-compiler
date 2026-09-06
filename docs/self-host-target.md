# Self-host target — compiler compiles itself

**Project north star (living tracker):** [#1492](https://github.com/PurHur/php-compiler/issues/1492) (was [#1056](https://github.com/PurHur/php-compiler/issues/1056))  
**Public status:** [development-status § North star](https://purhur.github.io/php-compiler/development-status.html#north-star-self-host)  
**M2 batch tracker:** [#1419](https://github.com/PurHur/php-compiler/issues/1419) (closed — work landed in PRs)  
**Release scope (Sep 2026):** [ADR #36393](adr/36393-selfhost-user-payoff.md) — user-payoff M5, not full-revision fixpoint

---

## The target (one sentence)

**A static `phpc` binary built from this repo’s PHP (`lib/` + `ext/`) compiles real user projects (including Composer apps) without host PHP, Composer, or vendor patches at cold boot.**

Research stretch (not a release gate): that same binary can compile the next revision of the compiler without Zend in the loop. See [ADR #36393](adr/36393-selfhost-user-payoff.md).

---

## Definition of done (release-scoped M5)

| Requirement | Meaning |
|-------------|---------|
| **Static `phpc`** | Gen-0 / prelinked cold boot produces a distributable `phpc` ([#36390](https://github.com/PurHur/php-compiler/issues/36390)) |
| **User project** | That binary builds a Composer app from the corpus ([#36380](https://github.com/PurHur/php-compiler/issues/36380) / [#36382](https://github.com/PurHur/php-compiler/issues/36382)) to a working native binary |
| **No `vendor/` at cold boot** | Parser/types/LLVM FFI prelinked once ([#1416](https://github.com/PurHur/php-compiler/issues/1416)); see [`bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md) |
| **Honest native emit** | `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1` refuses sidecar COPY ([#36146](https://github.com/PurHur/php-compiler/issues/36146)); no gen-0 fingerprint restamps ([#36145](https://github.com/PurHur/php-compiler/issues/36145)) |
| **Daily gate** | `make north-star5-verify-fast` green; `--strict` only for bootstrap/gen-0/vendor-prelink merges |
| **Compiler in PHP** | Front/middle/back end stay in `lib/`, `ext/` — not rewritten in C |
| **Small native floor OK** | `lib/AOT/runtime/*.c` + external `clang` via `lib/AOT/Linker.php` — **not** required to disappear |

**Not required for release M5:** gen-2 == gen-3 byte fixpoint · 100% Zend parity · in-process linker · production web polish (examples stay as regression fixtures). Fleet capacity for self-host / spine work stays ≤ ~25 % until corpus + perf targets move ([ADR #36393](adr/36393-selfhost-user-payoff.md)).

### Research ladder (tracked, not release-blocking)

| Requirement | Meaning |
|-------------|---------|
| **No Zend bootstrap** | `bin/compile.php` / `bin/vm.php` run as **compiled** code |
| **Full inventory / stub shrink** | Honest `bin/vm.php` closure; `PHP_COMPILER_SELFHOST_AOT` stubs minimal |
| **`--strict` ladder** | `make north-star5-verify ARGS=--strict` green (standalone AOT emit, prior [#21417](https://github.com/PurHur/php-compiler/issues/21417) / [#36144](https://github.com/PurHur/php-compiler/issues/36144)) |

---

## Where we are (Jul 2026, `master`)

| Layer | Today | Target |
|-------|-------|--------|
| **Bootstrap driver** | Prelinked gen-0 refreshed via honest inventory argv emit; native `build/bin-compile-aot-inventory` for M4/M5 | Compiled `bin/compile.php` only |
| **Bundle size** | **7410/7412** literal Phase A inventory in spine smoke | Full vm.php closure |
| **Inventory coverage** | **8348** / **8348** ✅ | Full closure |
| **HelloWorld** | ✅ `emit_path=native` via gen-0 argv emit helper (`DRIVER -o OUT SOURCE`; [#22178](https://github.com/PurHur/php-compiler/issues/22178)) | Native compile for arbitrary PHP |
| **Bootstrap loop (M4)** | `make bootstrap-loop-probe` full ladder ✅ — gen-1→gen-2, gen-2→gen-3 full spine, full-revision argv | Native full revision rebuild |
| **Vendor** | **7410/7412** vendor `object_ok`; committed `.o` cold boot without `vendor/` ✅; `make north-star5-verify-fast` daily ✅; `--strict` ❌ **red at step 4a2** ([#21417](https://github.com/PurHur/php-compiler/issues/21417)) | No Zend `vendor/autoload.php` at bootstrap |

### Gates (run after `script/apply-patches.sh`)

| Gate | Status |
|------|--------|
| M0 `bootstrap-selfhost-link.sh` | ✅ `compiler_minimal bundle OK` |
| M1 compile-smoke AOT echo | ✅ `compiler smoke` |
| M3 compile-smoke strict | ✅ `make bootstrap-selfhost-compile-smoke-strict` → `emit_path=native` (#2610) |
| M3 Runtime compile smoke strict | ✅ `make bootstrap-selfhost-runtime-compile-smoke-strict` → `emit_path=native` (#2610) |
| M3 compiler-unit strict | ✅ `make bootstrap-selfhost-compiler-unit-probe-strict` → `emit_path=native` (#2618) |
| M2 `BOOTSTRAP_LIB_SPINE_SMOKE=1` spine link | ✅ `compiler_lib_spine_smoke bundle OK` |
| M3 `make bootstrap-selfhost-helloworld` | ✅ `emit_path=native` — gen-0 argv emit helper compiles HelloWorld ([#22178](https://github.com/PurHur/php-compiler/issues/22178)); `BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT=1` green. Cold `BOOTSTRAP_M3_FORCE_EMIT_HELPER_LINK=1` still OOMs at default 6 GiB Docker PHP cap — use prelinked gen-0 argv driver (#9704) until inventory link memory shrinks |
| M4 `make bootstrap-loop-gen1-link` | ✅ gen-1 link + gen-2 smoke **`emit_path=native`** (`BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1`, #2611); **`BOOTSTRAP_M4_GEN2_STRICT=1` default-on** ([#8711](https://github.com/PurHur/php-compiler/issues/8711)); opt-in Zend bisect: `BOOTSTRAP_M4_GEN2_ZEND_FALLBACK=1`; presenter: [GETTING-STARTED §7](GETTING-STARTED.md) ([#2464](https://github.com/PurHur/php-compiler/issues/2464)) |
| M4 `make bootstrap-loop-gen1-full-spine-emit` | 🚧 gen-1→gen-2 full spine — heavy opt-in |
| M4 `make bootstrap-selfhost-full-revision-probe` | ✅ gen-2 inventory argv → gen-3 + fixture smoke ([#2880](https://github.com/PurHur/php-compiler/issues/2880)) |
| M4 `make bootstrap-loop-gen2-recompile-spine` | ✅ gen-2→gen-3 full spine native argv |
| M4 `make bootstrap-loop-probe` | 🚧 **DEGRADED** — gen-1→gen-2 native emit still segfaults; ladder falls back to prelinked sidecar COPY ([#21860](https://github.com/PurHur/php-compiler/issues/21860)). Default probe exits **2** (not false OK). Require real emit: `BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=1` ([#1498](https://github.com/PurHur/php-compiler/issues/1498), [#36146](https://github.com/PurHur/php-compiler/issues/36146)) — refuses sidecar COPY. M0/M2/M3 prerequisites green via committed prelinked gen-0 trust (#36145). |
| `make bootstrap-aot-link` | ✅ **7410/7412** |
| `make bootstrap-inventory-check` | ✅ **7410/7412** Phase A files, **0** source blockers |
| `make north-star5-verify-fast` | ✅ daily M5 PR gate (~1–2 min) |
| `make north-star5-verify ARGS=--strict` | ❌ **red on master** — step 4a2 `bootstrap-selfhost-driver-smoke`: standalone AOT emit leaves the LLVM builder detached ([#21417](https://github.com/PurHur/php-compiler/issues/21417)). Steps 1–4a pass. Was masked by a 4096M OOM ([#21104](https://github.com/PurHur/php-compiler/issues/21104)) and a `trigger_error` `ValueError` ([#21400](https://github.com/PurHur/php-compiler/issues/21400)), both now fixed |

---

## Milestone ladder

| Milestone | What it proves | Status | ~% |
|-----------|----------------|--------|-----|
| **M0** | AOT can link a **small** honest `lib/` subset | ✅ | 100% |
| **M1** | Bundle is **compiler-shaped** (lint + compile-smoke) | ✅ | 100% |
| **M2** | Spine grows toward full `bin/vm.php` inventory | ✅ **8348** / **8348** | **100%** |
| **M3** | Self-host binary **compiles external PHP** (HelloWorld) without Zend emit | ✅ `emit_path=native` via gen-0 argv helper ([#22178](https://github.com/PurHur/php-compiler/issues/22178)) | **~90%** |
| **M4** | Self-host binary **rebuilds** the next compiler tree | 🚧 ladder runs but gen-1→gen-2 is a COPY ([#21860](https://github.com/PurHur/php-compiler/issues/21860)) | **~60%** |
| **M5 (release)** | Static `phpc` builds user/Composer projects | 🚧 fast gate ✅; corpus app smoke open ([#36380](https://github.com/PurHur/php-compiler/issues/36380)); see [ADR #36393](adr/36393-selfhost-user-payoff.md) | **payoff** |
| **M5 (research)** | Full self-host; Zend retired from loop | 🚧 **`--strict` red at step 4a2** ([#21417](https://github.com/PurHur/php-compiler/issues/21417) / [#36144](https://github.com/PurHur/php-compiler/issues/36144)); `BOOTSTRAP_M5_NO_ZEND=1` empty `build/` ([#3053](https://github.com/PurHur/php-compiler/issues/3053)) | **~75%** |

**Indicative research composite:** **~65%** (weighted across M0–M5; see formula below). **Release** keys off user-payoff gates ([ADR #36393](adr/36393-selfhost-user-payoff.md)), not `--strict` / gen-2==gen-3.

### North star % (single formula)

| Indicator | Formula | Jul 2026 |
|-----------|---------|----------|
| **M2 spine progress** | `require_once` units in `compiler_lib_spine_smoke` ÷ Phase A inventory file count | **8348** / **8348** (`php script/bootstrap-spine-count.php`) |
| **Public “Self-host” row** | Same M2 ratio until M3–M5 gates add weight ([`development-status.md`](pages/development-status.md)) | **~97%** |
| **M5 vendor prelink** | `object_ok` packages ÷ 3 | **3 / 3** (cfg, types, llvm) |
| **Composite (internal)** | Milestone weights in table above (M0–M1 = 100%, M2 = spine %, M3–M5 = gate %) | **~65%** |

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
| `test/selfhost/compiler_lib_spine_smoke/main.php` | **7410/7412** Phase A | M2 complete ([#8559](https://github.com/PurHur/php-compiler/issues/8559)) |
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

- [`adr/36393-selfhost-user-payoff.md`](adr/36393-selfhost-user-payoff.md) — release-scoped M5 decision
- [`bootstrap-generations.md`](bootstrap-generations.md) — gen-0…gen-3 ladder and artifacts
- [`bootstrap-selfhost.md`](bootstrap-selfhost.md) — gates, waves, stub policy
- [`bootstrap-m5-fast-path.md`](bootstrap-m5-fast-path.md) — M3 incremental lowering playbook
- [`bootstrap-inventory.md`](bootstrap-inventory.md) — per-file inventory
- [`pages/development-status.md`](pages/development-status.md) — public milestone table
