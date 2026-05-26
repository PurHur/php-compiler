---
layout: status
title: Development status and progress
description: Authoritative snapshot of php-compiler — VM, JIT, AOT web deployment, and self-host bootstrap toward a full PHP compiler.
permalink: /development-status.html
---

*Last updated: May 2026. Edit this file when milestones change; keep in sync with [issue #78](https://github.com/PurHur/php-compiler/issues/78) (roadmap), [#1492](https://github.com/PurHur/php-compiler/issues/1492) (project north star — self-host; was [#1056](https://github.com/PurHur/php-compiler/issues/1056)), and the [README](https://github.com/PurHur/php-compiler/blob/master/README.md).*

## At a glance

| | |
|---|---|
| **Try it** | `git clone` → `composer install` → `./phpc test --fast` → [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |
| **Overall progress** | ~**50%** toward a self-hosting compiler (indicative) |
| **Wave 3 (May 2026)** | Language **12/12** · Stdlib **13/13** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **North star (self-host)** | M0–M1 ✅ · M2 spine **661/657** native link ✅ ([#1960](https://github.com/PurHur/php-compiler/issues/1960)) · M3 partial (native run ✅, emit 🚧 [#1937](https://github.com/PurHur/php-compiler/issues/1937)) · M5 ⬜ ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **Example tests** | `examples/000–009` — VM + AOT regression fixtures (not a project north star) |
| **Not Zend parity** | Subset compiler (not full Zend PHP) |

[← Visual overview](index.html) · [Repository](https://github.com/PurHur/php-compiler)

---

## What this project is

**php-compiler** is a PHP compiler that:

1. Parses PHP into a **control-flow graph** (via [php-cfg](https://github.com/ircmaxell/php-cfg))
2. Lowers the CFG to internal **opcodes** (`lib/Compiler.php`)
3. Executes on a **VM** (`phpc run`, `bin/vm.php`)
4. **JIT-compiles** hot paths to LLVM 9 (`lib/JIT.php`)
5. **AOT-links** whole programs to native binaries (`phpc build`, `bin/compile.php`)

Deployed apps can run **without Zend PHP** at runtime. Development and bootstrap still use system PHP today.

This document is the **public development status** for the project.

**Excluded from this site (repo-only):** generated capability matrices (`capabilities.md`, `capabilities-syntax.md`), bootstrap inventory tables (`bootstrap-inventory.md`), CI / gate matrices (`local-ci-matrix.md`, `miniwebapp-aot-unskip-matrix.md`), and other large generated maps. They stay in the [repository `docs/` tree](https://github.com/PurHur/php-compiler/tree/master/docs) for contributors — **not mirrored here and not linked from these public pages**.

---

## How complete is the compiler?

Indicative composite toward a **web-capable, self-hosting** compiler (not line-count parity with Zend PHP):

| Area | Progress | Summary |
|------|----------|---------|
| **Foundation** (CI, CLI, Docker) | ~88% | `phpc` CLI, local/Docker CI; GitHub Actions + CircleCI disabled ([#1338](https://github.com/PurHur/php-compiler/pull/1338), [#1340](https://github.com/PurHur/php-compiler/pull/1340)); contributor CI matrix doc in repo only |
| **Language** (OOP, types, CFG) | ~76% | VM/JIT OOP largely works; wave-3 language **12/12** on master; PHP 8 attributes VM v1 ([#1354](https://github.com/PurHur/php-compiler/issues/1354)); reflection phase-2 ([#1936](https://github.com/PurHur/php-compiler/issues/1936)) |
| **Stdlib** | ~58% | Wave-3 batch ([#1367](https://github.com/PurHur/php-compiler/issues/1367)–[#1379](https://github.com/PurHur/php-compiler/issues/1379)): **13/13** closed on master |
| **Web AOT** (build, deploy) | ~70% | Project link ✅; CLI execute ✅; examples **003–007** as integration tests |
| **Example harness** (003-MiniWebApp, 005-SessionsWeb, …) | ~90% | VM + AOT execute + default-on CI gates — **regression fixtures only** |
| **Self-host** (north star, M0–M5) | ~58% | M0–M1 ✅; M2 spine **661/657** native link ✅ ([#1960](https://github.com/PurHur/php-compiler/issues/1960)); M3 partial — native **run** ✅, native **emit** 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937), [#1402](https://github.com/PurHur/php-compiler/issues/1402)); M4–M5 ⬜ — [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md)

**Overall (indicative): ~52%** toward the [project north star](#north-star-self-host) below.

**Roadmap wave 3** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)): language **12/12** + stdlib **13/13** on master — tracker in repo [docs/roadmap-wave3.md](https://github.com/PurHur/php-compiler/blob/master/docs/roadmap-wave3.md). Attributes reflection phase-2: [#1936](https://github.com/PurHur/php-compiler/issues/1936).

Per-builtin and per-construct coverage detail is in generated contributor matrices in the repo (not published or linked from this site).

---

## Project north star {#north-star-self-host}

**Living tracker:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) · **Roadmap:** [#78](https://github.com/PurHur/php-compiler/issues/78) · **Process:** [#1025](https://github.com/PurHur/php-compiler/issues/1025)

All prioritized engineering work aims at this single north star. Web examples under `examples/` remain **integration test fixtures** (see [below](#example-integration-tests)) — they are not a parallel product goal.

#### Vision (definition of done)

The **compiler fully compiles itself** — the stretch goal behind every bootstrap milestone:

- Native **`phpc` / compiler binary** built from php-compiler `lib/` + policy-selected `ext/` (**no `vendor/`**)
- Zend PHP used only for the **last** bootstrap link step, then retired from the loop
- Self-hosted binary runs **real** `bin/compile.php` / `bin/vm.php` driver paths (not stub-only `echo` demos)
- Self-hosted binary compiles **`examples/000-HelloWorld`** without Zend
- Self-hosted binary compiles the **next** compiler revision — **bootstrap loop closed**
- Honest AOT bundle toward the full `bin/vm.php` inventory (**657** files), with `PHP_COMPILER_SELFHOST_AOT` stub surface **shrinking** as lowering lands

This is the **project north star**.

**Not required for M5 close:** in-process LLVM linker (`lib/AOT/Linker.php` may keep external `clang`); 100% Zend parity; production web-app polish. A small **C runtime** (`lib/AOT/runtime/*.c`) for linked binaries is expected — compiler logic stays in PHP.

#### Where we are today

Zend PHP still runs `bin/compile.php` during bootstrap. The output is a **curated, stub-tolerant** native bundle (`test/selfhost/compiler_minimal/`) — not yet a replacement for system PHP. With `PHP_COMPILER_SELFHOST_AOT=1`, many `Compiler` / `JIT` / VM paths use **LLVM stubs** so the bundle links; `SelfHostBuiltinPolicy` keeps ~40 stdlib builtins at real lowering and stubs the rest.

**Re-root doc (target + critical path):** [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md)

- **Contributor gates:** [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md) (repo only)
- **M3 incremental lowering:** [`docs/bootstrap-m5-fast-path.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-m5-fast-path.md) ([#1402](https://github.com/PurHur/php-compiler/issues/1402))
- **Inventory:** `php script/bootstrap-inventory.php` (generated `bootstrap-inventory.md` is contributor-only, not on this site)
- **M2 batch (done):** [#1419](https://github.com/PurHur/php-compiler/issues/1419), [#1497](https://github.com/PurHur/php-compiler/issues/1497) — spine **179 → 408** units (May 2026; string-format wrappers)

---

## Example integration tests {#example-integration-tests}

The `examples/` tree (**000–009**) is kept for **VM + AOT regression testing** — routing, templates, sessions, uploads, throw/catch, and deploy smoke. It is **not** a project north star; active product work targets [self-host](#north-star-self-host) ([#1492](https://github.com/PurHur/php-compiler/issues/1492)).

| Fixture | Role | CI |
|---------|------|-----|
| [003-MiniWebApp](https://github.com/PurHur/php-compiler/tree/master/examples/003-MiniWebApp) | Multi-file web app (router, templates, CGI superglobals) | Default-on gates: lint, VM, AOT link/execute ([#472](https://github.com/PurHur/php-compiler/issues/472)) |
| [005-SessionsWeb](https://github.com/PurHur/php-compiler/tree/master/examples/005-SessionsWeb) | Session/cookie smoke | Opt-in `SESSIONS_WEB_*` gates |
| [006-FileUploadWeb](https://github.com/PurHur/php-compiler/tree/master/examples/006-FileUploadWeb) | Multipart upload smoke | Opt-in `FILE_UPLOAD_WEB_*` gates |
| [007-ThrowsWeb](https://github.com/PurHur/php-compiler/tree/master/examples/007-ThrowsWeb) | `throw` / `catch` on invalid POST ([#2076](https://github.com/PurHur/php-compiler/issues/2076)) | VM `THROWS_WEB_SMOKE_GATE=1` default ([#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2125](https://github.com/PurHur/php-compiler/issues/2125)); AOT `THROWSWEB_*_GATE=1` default ([#2135](https://github.com/PurHur/php-compiler/issues/2135)) |

**Verify (optional):** `./phpc doctor --gates`, `make miniwebapp-gates`, or `make north-star1-verify` (legacy name — example regression bundle, not a north star). Details: [miniwebapp-gates.md](https://github.com/PurHur/php-compiler/blob/master/docs/miniwebapp-gates.md) (repo only). Historical tracker [#1044](https://github.com/PurHur/php-compiler/issues/1044) is closed; do not open new “north star web app” issues.

---

## Self-host milestone ladder {#self-host-milestone-ladder}

| Milestone | Meaning | Status |
|-----------|---------|--------|
| **M0 — Bundled subset runs** | ~109 literal `require_once` units in `test/selfhost/compiler_minimal/main.php` → `build/selfhost` prints `compiler_minimal bundle OK` | ✅ [#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913) |
| **M1 — Compiler-shaped bundle** | Bundled `Compiler.php` AOT lint; compile-smoke native link + AOT echo (`compiler smoke`); driver smoke toward `bin/compile.php` | ✅ [#1025](https://github.com/PurHur/php-compiler/issues/1025), [#1095](https://github.com/PurHur/php-compiler/issues/1095) |
| **M2 — Lib spine growth** | `compiler_lib_spine_smoke` bundle toward full `bin/vm.php` inventory (**657** files) | ✅ native link **661** / 657 units (~93%; 1 deferred native-link [#2201](https://github.com/PurHur/php-compiler/issues/2201)) |
| **M3 — Native compiles PHP** | Self-host binary compiles external PHP without Zend **emit** | 🚧 partial — HelloWorld AOT **run** ✅; native **emit** blocked (emit TU / `Runtime` spine — [#1937](https://github.com/PurHur/php-compiler/issues/1937), [#1768](https://github.com/PurHur/php-compiler/issues/1768)) |
| **M4 — Bootstrap loop** | Native toolchain rebuilds the **next** compiler sources (same tree, new revision) | ⬜ |
| **M5 — Full self-host** | Real `bin/vm.php` / `bin/compile.php` path on full inventory; **no Zend bootstrap** | ⬜ **north star** ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |

#### Scale (why M5 is big)

| Set | ~Files | Notes |
|-----|--------|-------|
| `compiler_minimal` bundle (M0) | **109** | Literal `require_once` closure |
| `compiler_lib_spine_smoke` (M2) | **661** | vm.php-path lib/ + ext/standard growth bundl
| Top-level `lib/*.php` | **14** | Per-file AOT lint ✅ |
| Full vm.php inventory | **657** | Phase A inventory (`php script/bootstrap-inventory.php`) |

**M2 → M5 gap:** remaining inventory paths + deferred native-link units; **M3 native emit** (`parseAndCompile` + standalone without Zend — [#1402](https://github.com/PurHur/php-compiler/issues/1402)); **M4** bootstrap loop ([#1498](https://github.com/PurHur/php-compiler/issues/1498)); vendor prelink ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

#### Critical path to “compiler compiles itself”

1. **Close M3** — native emit for HelloWorld + compile-smoke (not just native run); emit TU / `Runtime` lowering ([#1937](https://github.com/PurHur/php-compiler/issues/1937), [#1402](https://github.com/PurHur/php-compiler/issues/1402))
2. **Finish M2** — spine → full inventory (or honest closure); optional `src/cli.php` ([#1467](https://github.com/PurHur/php-compiler/issues/1467))
3. **M4** — native binary rebuilds the next compiler revision
4. **M5** — vendor prelink + stub retirement; real `bin/vm.php` / `bin/compile.php` without Zend cold boot

Details: [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md).

### Bootstrap phases (repo scripts)

| Phase | Command / doc | Status |
|-------|---------------|--------|
| **A — Inventory** | `php script/bootstrap-inventory.php --check` | ✅ **657** files; 0 source blockers |
| **B — AOT lint** | `lib/*.php`, `test/bootstrap-aot/`, selfhost bundles | ✅ (requires `script/apply-patches.sh` locally) |
| **C — Native fixtures** | `make bootstrap-aot-link` | ✅ **71/71** bootstrap-aot link targets OK |
| **D — `lib/` in bundle** | `lib/OpCode.php` etc. | ✅ [#540](https://github.com/PurHur/php-compiler/issues/540) |
| **E — Waves** | `./script/bootstrap-wave-check.sh` | ✅ `NEXT_LOWER: none` on probe |

### Automated bootstrap gates

| Gate | Command | Status |
|------|---------|--------|
| Inventory | `php script/bootstrap-inventory.php --check` | ✅ M0+ |
| Lib AOT lint | `php bin/compile.php -l lib/*.php` | ✅ 14/14 top-level `lib/*.php` |
| Bundled compiler lint | `./script/bootstrap-selfhost-lint.sh` | ✅ M0 |
| Native link + run | `./script/bootstrap-selfhost-link.sh` | ✅ M0 |
| Compile smoke link | `make bootstrap-selfhost-compile-smoke` | ✅ M1 |
| Compile smoke AOT echo | `make bootstrap-selfhost-compile-smoke-run` | ✅ M1 |
| M2 spine native link | `BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke` | ✅ `compiler_lib_spine_smoke bundle OK` (**661** / **657** units; [#2001](https://github.com/PurHur/php-compiler/issues/2001)) |
| M3 HelloWorld strict | `BOOTSTRAP_M3_HELLOWORLD_STRICT=1` → `bootstrap-selfhost-helloworld-probe.sh` | 🚧 opt-in — fails until native emit helper links + `emit_path=native` ([#1493](https://github.com/PurHur/php-compiler/issues/1493), [#1937](https://github.com/PurHur/php-compiler/issues/1937); default gate off [#1866](https://github.com/PurHur/php-compiler/issues/1866)) |
| M3 compile-smoke probe | `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1` (default) | ✅ partial — native **run** ✅; strict native emit 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)) |
| M3 compile-smoke strict | `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=1` → `bootstrap-selfhost-compile-smoke-probe.sh` | opt-in gate default `0` until native emit lands ([#1937](https://github.com/PurHur/php-compiler/issues/1937), [#2165](https://github.com/PurHur/php-compiler/issues/2165)) |
| Wave gate | `./script/bootstrap-wave-check.sh` | ✅ locally / Docker; GHA workflow disabled |
| Next includes probe | `php script/bootstrap-selfhost-next-includes.php` | 🚧 bundle growth |

#### Verify locally

```bash
script/apply-patches.sh   # required on host (php-cfg match, etc.; Docker CI does this)
make bootstrap-wave-check
./script/bootstrap-selfhost-link.sh
make bootstrap-selfhost-compile-smoke-run
BOOTSTRAP_LIB_SPINE_SMOKE=1 ./script/bootstrap-wave-check.sh --with-lib-spine-smoke
make bootstrap-selfhost-helloworld
php script/bootstrap-selfhost-next-includes.php
```

#### Self-host blockers (priority)

| Area | Issues |
|------|--------|
| Class methods / JIT objects | [#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145), [#828](https://github.com/PurHur/php-compiler/issues/828) |
| Namespaces + bundle growth | [#84](https://github.com/PurHur/php-compiler/issues/84) |
| Try/catch unwind | [#57](https://github.com/PurHur/php-compiler/issues/57) |
| Parser / vendor strategy | php-cfg, php-types, php-llvm — patches on host; M5 prelink ([#1416](https://github.com/PurHur/php-compiler/issues/1416)) |
| M3 native compile driver | `Runtime::__construct` → `loadJit` → `standalone`; [#1402](https://github.com/PurHur/php-compiler/issues/1402), `docs/bootstrap-m5-fast-path.md` |
| External linker | `lib/AOT/Linker.php` excluded from bundle (`shell_exec`) |

---

## Development phases (roadmap #78) {#development-phases-roadmap-78}

GitHub issues use labels `phase-0:Foundation` … `phase-5:reference-app`. Delivery order:

| Phase | Focus | Representative status |
|-------|--------|------------------------|
| **0 — Foundation** | CI, `phpc` CLI, Docker, docs, JIT compliance | Largely ✅ |
| **1 — Language** | OOP, types, includes, `::class` | VM/JIT strong; native AOT gaps remain |
| **2 — Stdlib** | Web builtins, filesystem, JSON, regex | Ongoing batches; audit in repo |
| **3 — Web AOT** | `phpc build --project`, deploy, runtime includes | Link ✅; execute ✅ (example fixtures **003–009**) |
| **4 — Polish** | Example regression + language/stdlib batches | Maintenance only — not a north star |

**Current active phase:** Self-host bootstrap (M3–M5) per [#1492](https://github.com/PurHur/php-compiler/issues/1492). Example web gates stay in CI as regression tests.

---

## Shipped examples (000–009)

| Example | VM | AOT link | AOT execute | Deploy / notes |
|---------|----|----------|-------------|----------------|
| 000–002, 004 | ✅ | ✅ | ✅ | — |
| 003-MiniWebApp | ✅ | ✅ | ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764), [#676](https://github.com/PurHur/php-compiler/issues/676)) | Primary web integration test |
| 005-SessionsWeb | ✅ `phpc serve` + `SESSIONS_WEB_SMOKE_GATE=1` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) | ✅ `SESSIONS_WEB_AOT_LINK_GATE=1` ([#1946](https://github.com/PurHur/php-compiler/issues/1946)) | ✅ opt-in `SESSIONS_WEB_AOT_SMOKE_GATE=1` ([#1891](https://github.com/PurHur/php-compiler/issues/1891), [#1923](https://github.com/PurHur/php-compiler/issues/1923)) | Deploy smoke opt-in `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1` ([#1893](https://github.com/PurHur/php-compiler/issues/1893)); default-on tracked in [#1954](https://github.com/PurHur/php-compiler/issues/1954) / [#1967](https://github.com/PurHur/php-compiler/issues/1967) |
| 006-FileUploadWeb | ✅ `phpc serve` + `FILE_UPLOAD_WEB_SMOKE_GATE=1` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) | ✅ `FILE_UPLOAD_WEB_AOT_LINK_GATE=1` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)) | ✅ `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) | Deploy smoke opt-in `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1` ([#2038](https://github.com/PurHur/php-compiler/issues/2038)); default-on tracked in [#2042](https://github.com/PurHur/php-compiler/issues/2042) |
| 007-ThrowsWeb | ✅ `phpc serve` + `THROWS_WEB_SMOKE_GATE=1` ([#2076](https://github.com/PurHur/php-compiler/issues/2076), [#2093](https://github.com/PurHur/php-compiler/issues/2093)) | ✅ `THROWSWEB_AOT_LINK_GATE=1` ([#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2135](https://github.com/PurHur/php-compiler/issues/2135)) | ✅ `THROWSWEB_AOT_SMOKE_GATE=1` ([#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2135](https://github.com/PurHur/php-compiler/issues/2135)) | `make examples-throws-smoke` ([#2141](https://github.com/PurHur/php-compiler/issues/2141)); deploy smoke opt-in `THROWSWEB_DEPLOY_SMOKE_GATE=1` ([#2124](https://github.com/PurHur/php-compiler/issues/2124), [#2264](https://github.com/PurHur/php-compiler/issues/2264)) |
| 008-SelfHostProbe | ✅ `./phpc run` ([#2207](https://github.com/PurHur/php-compiler/issues/2207)) | — | 📋 optional | North Star 2 presenter — `make north-star2-verify` |
| 009-FastCGIWeb | ✅ `phpc run` / `phpc serve` ([#2331](https://github.com/PurHur/php-compiler/issues/2331)); VM smoke opt-in `FASTCGI_WEB_SMOKE_GATE=1` ([#2351](https://github.com/PurHur/php-compiler/issues/2351)) | ✅ `phpc build --project` | 📋 FastCGI adapter execute ([#173](https://github.com/PurHur/php-compiler/issues/173)); AOT CGI opt-in `FASTCGI_WEB_AOT_SMOKE_GATE=1` ([#2352](https://github.com/PurHur/php-compiler/issues/2352)) | Deploy smoke opt-in `FASTCGI_WEB_DEPLOY_SMOKE_GATE=1` ([#2359](https://github.com/PurHur/php-compiler/issues/2359)); `make examples-fastcgiweb-smoke` |

Commands: `./phpc run`, `./phpc build`, `./phpc serve`, `make examples-aot-smoke` (see [README](https://github.com/PurHur/php-compiler/blob/master/README.md) and [examples/README.md](https://github.com/PurHur/php-compiler/blob/master/examples/README.md)).

---

## Compiler pipeline (one paragraph per stage)

1. **Parse & CFG** — PHP source → PHPCfg; `Compiler` lowers statements/expressions to `OpCode`s. Unsupported constructs throw `LogicException` (“not yet lowered”).
2. **VM** — Interpreter for dev/tests; full PHP subset not implemented.
3. **JIT** — LLVM 9 for hot paths and stdlib `Internal` builtins (`ext/standard/`, `ext/types/`).
4. **AOT** — Whole-program link to a native binary; external `clang` via `lib/AOT/Linker.php` (not in self-host bundle yet).

---

## Major open blockers

| Issue | Area | Impact |
|-------|------|--------|
| [#878](https://github.com/PurHur/php-compiler/issues/878)–[#849](https://github.com/PurHur/php-compiler/issues/849) | AOT bisect (optional) | Layout/includes edge refinements in native 003 |
| [#828](https://github.com/PurHur/php-compiler/issues/828) | Self-host JIT | `Object_.php` external property children |
| [#84](https://github.com/PurHur/php-compiler/issues/84) | Self-host tree | Full `lib/` bundle growth + namespaces |
| [#57](https://github.com/PurHur/php-compiler/issues/57) | Self-host runtime | Try/catch unwind in native bundle |
| M3–M5 | North star | Native PHP → rebuild compiler → full tree ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |

---

## How to contribute

1. **Try the project:** [GETTING-STARTED.md](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) (clone, demo script, `phpc` cheat sheet).
2. Pick an issue by [phase label](https://github.com/PurHur/php-compiler/issues) or the self-host tracker [#1492](https://github.com/PurHur/php-compiler/issues/1492). Example-only issues ([#1750](https://github.com/PurHur/php-compiler/issues/1750), [#587](https://github.com/PurHur/php-compiler/issues/587)) are optional regression work.
3. For self-host work, run `./script/bootstrap-wave-check.sh` before opening a PR.
4. Update **this file** (`docs/pages/development-status.md`) when a user-visible milestone lands (and sync [README](https://github.com/PurHur/php-compiler/blob/master/README.md) / [index.html](index.html) if the public story changed).

- [North star tracker #1492](https://github.com/PurHur/php-compiler/issues/1492) (self-host)
- [Example integration tests](#example-integration-tests) (003-MiniWebApp gate ladder)
- [Open issues](https://github.com/PurHur/php-compiler/issues)
- [Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)
- [Living roadmap #78](https://github.com/PurHur/php-compiler/issues/78)

---

[← Back to status overview](index.html)
