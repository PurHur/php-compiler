---
layout: status
title: Development status and progress
description: Authoritative snapshot of php-compiler — VM, JIT, AOT web deployment, and self-host bootstrap toward a full PHP compiler.
permalink: /development-status.html
---

*Last updated: May 2026. Edit this file when milestones change; keep in sync with [issue #78](https://github.com/PurHur/php-compiler/issues/78), closed [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1 achieved), [#1492](https://github.com/PurHur/php-compiler/issues/1492) (North Star 2; was [#1056](https://github.com/PurHur/php-compiler/issues/1056)), and the [README](https://github.com/PurHur/php-compiler/blob/master/README.md).*

## At a glance

| | |
|---|---|
| **Try it** | `git clone` → `composer install` → `./phpc test --fast` → [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |
| **Overall progress** | ~**50%** toward web-capable + self-hosting compiler (indicative) |
| **Wave 3 (May 2026)** | Language **10/13** · Stdlib **12/13** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **North Star 1** | **Achieved** — reference web app VM + AOT execute + default-on CI gates ([#1044](https://github.com/PurHur/php-compiler/issues/1044) closed); follow-ups [#1750](https://github.com/PurHur/php-compiler/issues/1750), [#587](https://github.com/PurHur/php-compiler/issues/587), [#445](https://github.com/PurHur/php-compiler/issues/445), [#173](https://github.com/PurHur/php-compiler/issues/173) |
| **North Star 2** | Self-compile — M0–M1 ✅ · M2 spine **606/611** native link ✅ ([#1960](https://github.com/PurHur/php-compiler/issues/1960)) · M3 HelloWorld strict ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)) · M5 ⬜ ([#1492](https://github.com/PurHur/php-compiler/issues/1492))
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
| **Language** (OOP, types, CFG) | ~76% | VM/JIT OOP largely works; wave-3 language **12/12** on master; PHP 8 attributes VM v1 ([#1354](https://github.com/PurHur/php-compiler/issues/1354)) |
| **Stdlib** | ~58% | Wave-3 batch ([#1367](https://github.com/PurHur/php-compiler/issues/1367)–[#1379](https://github.com/PurHur/php-compiler/issues/1379)): **13/13** closed on master |
| **Web AOT** (build, deploy) | ~70% | Project link ✅; CLI execute ✅; HTTP serve-aot / layout-edge bisect 🚧 |
| **Reference app** (MiniWebApp) | ~90% | North Star 1 **achieved** — VM + AOT execute + default-on gates; optional bisect [#1750](https://github.com/PurHur/php-compiler/issues/1750) |
| **Self-host** (North Star 2, M0–M5) | 100% | M0–M1 ✅; M2 spine **606/611** native link ✅ ([#1960](https://github.com/PurHur/php-compiler/issues/1960)); M3 HelloWorld strict ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)), compile-smoke 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); M4–M5 ⬜ — [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md)

**Overall (indicative): ~52%** toward the stated north stars below.

**Roadmap wave 3** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)): tracker in repo [docs/roadmap-wave3.md](https://github.com/PurHur/php-compiler/blob/master/docs/roadmap-wave3.md).

Per-builtin and per-construct coverage detail is in generated contributor matrices in the repo (not published or linked from this site).

---

## Two north stars

Work is prioritized along **two parallel tracks**:

### 1. Web-app north star {#north-star-1-web-app}

**Status:** **Achieved** (closed [#1044](https://github.com/PurHur/php-compiler/issues/1044), batch 77) · **Roadmap:** [#78](https://github.com/PurHur/php-compiler/issues/78) · **Gate ladder:** [#472](https://github.com/PurHur/php-compiler/issues/472) · **Presenter:** `make north-star1-verify` / `./phpc doctor --gates` · **Follow-ups:** [#1750](https://github.com/PurHur/php-compiler/issues/1750) (layout-edge bisect), [#587](https://github.com/PurHur/php-compiler/issues/587) (JIT project gate), [#445](https://github.com/PurHur/php-compiler/issues/445) (deploy docs), [#173](https://github.com/PurHur/php-compiler/issues/173) (FastCGI)

#### Vision (definition of done)

Compile and run a **normal small PHP web application** end-to-end **without Zend PHP at runtime**:

- Multiple PHP files (`public/index.php`, classes, `config.php`, templates)
- **CGI-style superglobals** (`$_GET`, `$_POST`, `$_SERVER`, …) for request dispatch
- **HTML templates** via `include` / partials (not a single `echo` script)
- Optional **HTTP headers**, form POST, and a **JSON API** route
- **`phpc build --project`** → native binary; **`phpc deploy`** → dist tree for CGI/FastCGI ([#609](https://github.com/PurHur/php-compiler/issues/609), [#718](https://github.com/PurHur/php-compiler/issues/718))
- **Parity:** native `.phpc/bin/app` output matches `phpc serve` / VM for the reference route matrix

This is **North Star 1**. **Achieved on `master`:** VM serve, AOT link, AOT execute (query + PATH_INFO), and default-on CI gates for MiniWebApp ([#1044](https://github.com/PurHur/php-compiler/issues/1044) closed). Remaining work is **optional polish** — layout-edge bisect ([#1750](https://github.com/PurHur/php-compiler/issues/1750)), JIT project smoke ([#587](https://github.com/PurHur/php-compiler/issues/587)), production deploy docs ([#445](https://github.com/PurHur/php-compiler/issues/445)), FastCGI adapter ([#173](https://github.com/PurHur/php-compiler/issues/173)) — not blocked on [#764](https://github.com/PurHur/php-compiler/issues/764).

It is **orthogonal** to [North Star 2 (self-host)](#north-star-2-self-host) — the compiler compiling its own `lib/` tree.

#### Reference application: `003-MiniWebApp`

Canonical app: [`examples/003-MiniWebApp`](https://github.com/PurHur/php-compiler/tree/master/examples/003-MiniWebApp) · scaffold: `phpc init --profile miniwebapp`

| Route | Method | Behavior |
|-------|--------|----------|
| `/` or `/index.php` | GET | Home page (layout + config) |
| `/index.php/hello?name=` | GET | Greeting |
| `/index.php/contact` | POST | Form thank-you |
| `/index.php/api/status` | GET | JSON status |
| `?route=…` | GET/POST | Legacy query dispatch (still supported) |

**Layout:** `phpc.json` manifest, `public/index.php` (PATH_INFO + query fallback), `src/Router.php`, `templates/` (runtime includes), `assets/style.css`.

#### Progress snapshot

| Layer | Status | Notes |
|-------|--------|-------|
| **Lint** (`phpc lint --all`) | ✅ | Class methods, includes, superglobals ([#539](https://github.com/PurHur/php-compiler/issues/539)) |
| **VM serve** | ✅ | `phpc serve` + PATH_INFO curls ([#489](https://github.com/PurHur/php-compiler/issues/489)) |
| **VM CLI matrix** | ✅ | `MiniWebApp*VmCli` in `ci-fast.sh` ([#597](https://github.com/PurHur/php-compiler/issues/597)) |
| **Web shell smoke** | ✅ | `examples-web-smoke.sh` ([#664](https://github.com/PurHur/php-compiler/issues/664)) |
| **AOT link** | ✅ | `phpc build --project` ([#752](https://github.com/PurHur/php-compiler/issues/752)) |
| **AOT execute** | ✅ **query + PATH_INFO** | Home, hello, contact, api/status via CLI + CGI ([#747](https://github.com/PurHur/php-compiler/issues/747), [#764](https://github.com/PurHur/php-compiler/issues/764)); optional layout-edge bisect ladder below |
| **AOT HTTP / deploy 003** | 🚧 **partial** | `MiniWebAppServeAotTest` default-on in full CI ([#1524](https://github.com/PurHur/php-compiler/issues/1524), [#1067](https://github.com/PurHur/php-compiler/issues/1067)); shell `--aot` curls opt-in ([#833](https://github.com/PurHur/php-compiler/issues/833)) |

Examples **000–004** pass VM + AOT link + AOT execute. **003-MiniWebApp** is the integration stress test that closed North Star 1 ([#1044](https://github.com/PurHur/php-compiler/issues/1044)).

#### CI gate ladder (stages 0 → 4d)

Run `./script/miniwebapp-gates.sh`, `phpc doctor --gates`, or the presenter bundle `make north-star1-verify` ([#1845](https://github.com/PurHur/php-compiler/issues/1845)). Details: [miniwebapp-gates.md](https://github.com/PurHur/php-compiler/blob/master/docs/miniwebapp-gates.md) (repo only).

| Stage | Check | Default on `master` |
|-------|--------|---------------------|
| 1 | Lint green | ✅ |
| 1b | VM CLI route matrix | ✅ |
| 2 | PHPUnit `ServeTest` @miniwebapp | ✅ |
| 3 | Examples web-smoke (curl) | ✅ |
| 4a | AOT dry-run lint | probe |
| 4b | AOT link | ✅ |
| 4b2 | AOT execute (PHPUnit) | ✅ `MINIWEBAPP_AOT_EXECUTE_GATE=1` default ([#747](https://github.com/PurHur/php-compiler/issues/747)) |
| 4 serve-aot | `MiniWebAppServeAotTest` HTTP | ✅ `MINIWEBAPP_SERVE_AOT_GATE=1` default ([#1524](https://github.com/PurHur/php-compiler/issues/1524), [#1067](https://github.com/PurHur/php-compiler/issues/1067)) |
| 4c | `examples-aot-smoke` 003 slice | ✅ ([#683](https://github.com/PurHur/php-compiler/issues/683)) |
| 4d | Deploy smoke (003) | ✅ `DEPLOY_SMOKE_003_EXECUTE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |

#### AOT execute bisect ladder (optional layout-edge refinements; core execute ✅ — tracked in [#1750](https://github.com/PurHur/php-compiler/issues/1750))

`MiniWebAppAotExecuteTest` and `examples-aot-smoke` 003 are green on `master`. The ladder below tracks **optional** layout/include edge cases — see [#78](https://github.com/PurHur/php-compiler/issues/78) bisect table:

| Step | Issue | Focus |
|------|-------|--------|
| ✅ | [#848](https://github.com/PurHur/php-compiler/issues/848), [#806](https://github.com/PurHur/php-compiler/issues/806) | isset / require_return |
| 🚧 | [#878](https://github.com/PurHur/php-compiler/issues/878) | Nested two-tier includes |
| 🚧 | [#867](https://github.com/PurHur/php-compiler/issues/867) | `miniwebapp_render_home` phpt |
| 🚧 | [#866](https://github.com/PurHur/php-compiler/issues/866) | `$_SERVER` in included `layout.php` |
| 🚧 | [#846](https://github.com/PurHur/php-compiler/issues/846), [#831](https://github.com/PurHur/php-compiler/issues/831), [#832](https://github.com/PurHur/php-compiler/issues/832) | Layout partials, contact, private methods |
| 🚧 | [#849](https://github.com/PurHur/php-compiler/issues/849) | JSON `api/status` in class method |
| 🚧 | [#784](https://github.com/PurHur/php-compiler/issues/784), [#807](https://github.com/PurHur/php-compiler/issues/807) | Title-branch partial includes |

DevEx: [#879](https://github.com/PurHur/php-compiler/issues/879) `miniwebapp-aot-bisect.sh`, [#880](https://github.com/PurHur/php-compiler/issues/880) `@group miniwebapp-bisect`.

#### Verify locally

```bash
make north-star1-verify          # presenter: doctor --gates + ci-fast + AOT execute (#1044)
./phpc doctor --gates          # gate ladder status without full CI
./phpc lint --all examples/003-MiniWebApp
./phpc serve examples/003-MiniWebApp
cd examples/003-MiniWebApp && ../../phpc build --project .
QUERY_STRING=route=home REQUEST_METHOD=GET ./.phpc/bin/app | wc -c   # expect non-zero
make miniwebapp-gates
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest
./script/ci-local.sh --filter MiniWebAppServeAot
MINIWEBAPP_SERVE_AOT_GATE=0 ./script/ci-local.sh --filter MiniWebAppServeAot  # skip during iteration
```

#### What is out of scope (for this north star)

- Full Zend PHP compatibility (contributor capability matrices in repo only)
- Sessions/auth stacks, databases, Composer autoload at runtime
- Self-hosting the compiler ([North Star 2](#north-star-2-self-host))

### 2. Self-host north star {#north-star-2-self-host}

**Living tracker:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) · **Roadmap:** [#78](https://github.com/PurHur/php-compiler/issues/78) · **Process:** [#1025](https://github.com/PurHur/php-compiler/issues/1025)

#### Vision (definition of done)

The **compiler fully compiles itself** — the stretch goal behind every bootstrap milestone:

- Native **`phpc` / compiler binary** built from php-compiler `lib/` + policy-selected `ext/` (**no `vendor/`**)
- Zend PHP used only for the **last** bootstrap link step, then retired from the loop
- Self-hosted binary runs **real** `bin/compile.php` / `bin/vm.php` driver paths (not stub-only `echo` demos)
- Self-hosted binary compiles **`examples/000-HelloWorld`** without Zend
- Self-hosted binary compiles the **next** compiler revision — **bootstrap loop closed**
- Honest AOT bundle toward the full `bin/vm.php` inventory (**611** files), with `PHP_COMPILER_SELFHOST_AOT` stub surface **shrinking** as lowering lands

This is **North Star 2**. It is **orthogonal** to [North Star 1 (web app)](#north-star-1-web-app) — user-facing web apps vs. the compiler eating its own `lib/` tree.

**Not required for M5 close:** in-process LLVM linker (`lib/AOT/Linker.php` may keep external `clang`); 100% Zend parity; replacing North Star 1. A small **C runtime** (`lib/AOT/runtime/*.c`) for linked binaries is expected — compiler logic stays in PHP.

#### Where we are today

Zend PHP still runs `bin/compile.php` during bootstrap. The output is a **curated, stub-tolerant** native bundle (`test/selfhost/compiler_minimal/`) — not yet a replacement for system PHP. With `PHP_COMPILER_SELFHOST_AOT=1`, many `Compiler` / `JIT` / VM paths use **LLVM stubs** so the bundle links; `SelfHostBuiltinPolicy` keeps ~40 stdlib builtins at real lowering and stubs the rest.

**Re-root doc (target + critical path):** [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md)

- **Contributor gates:** [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md) (repo only)
- **M3 incremental lowering:** [`docs/bootstrap-m5-fast-path.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-m5-fast-path.md) ([#1402](https://github.com/PurHur/php-compiler/issues/1402))
- **Inventory:** `php script/bootstrap-inventory.php` (generated `bootstrap-inventory.md` is contributor-only, not on this site)
- **M2 batch (done):** [#1419](https://github.com/PurHur/php-compiler/issues/1419), [#1497](https://github.com/PurHur/php-compiler/issues/1497) — spine **179 → 408** units (May 2026; string-format wrappers)

---

## Self-host milestone ladder {#self-host-milestone-ladder}

| Milestone | Meaning | Status |
|-----------|---------|--------|
| **M0 — Bundled subset runs** | ~109 literal `require_once` units in `test/selfhost/compiler_minimal/main.php` → `build/selfhost` prints `compiler_minimal bundle OK` | ✅ [#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913) |
| **M1 — Compiler-shaped bundle** | Bundled `Compiler.php` AOT lint; compile-smoke native link + AOT echo (`compiler smoke`); driver smoke toward `bin/compile.php` | ✅ [#1025](https://github.com/PurHur/php-compiler/issues/1025), [#1095](https://github.com/PurHur/php-compiler/issues/1095) |
| **M2 — Lib spine growth** | `compiler_lib_spine_smoke` bundle toward full `bin/vm.php` inventory (**611** files) | ✅ native link **606** / 611 units (100%; 5 deferred [#2066](https://github.com/PurHur/php-compiler/issues/2066); [#1960](https://github.com/PurHur/php-compiler/issues/1960), [#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **M3 — Native compiles PHP** | HelloWorld strict native emit ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)); compile-smoke fixture 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)) | 🚧 partial ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **M4 — Bootstrap loop** | Native toolchain rebuilds the **next** compiler sources (same tree, new revision) | ⬜ |
| **M5 — Full self-host** | Real `bin/vm.php` / `bin/compile.php` path on full inventory; **no Zend bootstrap** | ⬜ **north star** ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |

#### Scale (why M5 is big)

| Set | ~Files | Notes |
|-----|--------|-------|
| `compiler_minimal` bundle (M0) | **109** | Literal `require_once` closure |
| `compiler_lib_spine_smoke` (M2) | **606** | vm.php-path lib/ + ext/standard growth bundl
| Top-level `lib/*.php` | **14** | Per-file AOT lint ✅ |
| Full vm.php inventory | **611** | Phase A inventory (`php script/bootstrap-inventory.php`) |

**M2 → M5 gap:** ~**152** inventory files still outside the honest native bundle; plus native compile driver (`parseAndCompile` emit, [#1402](https://github.com/PurHur/php-compiler/issues/1402)); vendor prelink ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

#### Critical path to “compiler compiles itself”

1. **Close M3** — HelloWorld strict emit ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)); compile-smoke native emit ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); expand real lowering on compile spine ([#1402](https://github.com/PurHur/php-compiler/issues/1402))
2. **Finish M2** — spine → full inventory (or honest closure); optional `src/cli.php` ([#1467](https://github.com/PurHur/php-compiler/issues/1467))
3. **M4** — native binary rebuilds the next compiler revision
4. **M5** — vendor prelink + stub retirement; real `bin/vm.php` / `bin/compile.php` without Zend cold boot

Details: [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md).

### Bootstrap phases (repo scripts)

| Phase | Command / doc | Status |
|-------|---------------|--------|
| **A — Inventory** | `php script/bootstrap-inventory.php --check` | ✅ **611** files; 0 source blockers |
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
| M2 spine native link | `BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke` | ✅ `compiler_lib_spine_smoke bundle OK` (**606** / **611** units; [#2001](https://github.com/PurHur/php-compiler/issues/2001), [#2066](https://github.com/PurHur/php-compiler/issues/2066)) |
| M3 HelloWorld strict | `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1` → `bootstrap-selfhost-helloworld-probe.sh` | ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)); opt-in gate default `0` until default-on ([#1866](https://github.com/PurHur/php-compiler/issues/1866)) |
| M3 compile-smoke probe | `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1` (default) | ✅ partial — native **run** ✅; strict native emit 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)) |
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
| **3 — Web AOT** | `phpc build --project`, deploy, runtime includes | Link ✅; execute ✅ ([North Star 1](#north-star-1-web-app)) |
| **4 — Polish** | North Star 1 achieved ([#1044](https://github.com/PurHur/php-compiler/issues/1044)); optional bisect [#1750](https://github.com/PurHur/php-compiler/issues/1750), JIT [#587](https://github.com/PurHur/php-compiler/issues/587), deploy [#445](https://github.com/PurHur/php-compiler/issues/445) | Active |

**Current active phase:** Phase 4 polish (optional layout-edge bisect + HTTP/JIT/deploy follow-ups) while self-host waves continue in parallel.

---

## Shipped examples (000–005)

| Example | VM | AOT link | AOT execute | Deploy / notes |
|---------|----|----------|-------------|----------------|
| 000–002, 004 | ✅ | ✅ | ✅ | — |
| 003-MiniWebApp | ✅ | ✅ | ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764), [#676](https://github.com/PurHur/php-compiler/issues/676)) | North Star 1 reference app |
| 005-SessionsWeb | ✅ `phpc serve` + `SESSIONS_WEB_SMOKE_GATE=1` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) | ✅ `SESSIONS_WEB_AOT_LINK_GATE=1` ([#1946](https://github.com/PurHur/php-compiler/issues/1946)) | ✅ opt-in `SESSIONS_WEB_AOT_SMOKE_GATE=1` ([#1891](https://github.com/PurHur/php-compiler/issues/1891), [#1923](https://github.com/PurHur/php-compiler/issues/1923)) | Deploy smoke opt-in `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1` ([#1893](https://github.com/PurHur/php-compiler/issues/1893)); default-on tracked in [#1954](https://github.com/PurHur/php-compiler/issues/1954) / [#1967](https://github.com/PurHur/php-compiler/issues/1967) |

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
| M3–M5 | North Star 2 | Native PHP → rebuild compiler → full tree ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |

---

## How to contribute

1. **Try the project:** [GETTING-STARTED.md](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) (clone, demo script, `phpc` cheat sheet).
2. Pick an issue by [phase label](https://github.com/PurHur/php-compiler/issues), [#1750](https://github.com/PurHur/php-compiler/issues/1750) / [#587](https://github.com/PurHur/php-compiler/issues/587) (North Star 1 follow-ups; [#1044](https://github.com/PurHur/php-compiler/issues/1044) closed), [#1492](https://github.com/PurHur/php-compiler/issues/1492) (North Star 2), or self-host bootstrap issues.
3. For self-host work, run `./script/bootstrap-wave-check.sh` before opening a PR.
4. Update **this file** (`docs/pages/development-status.md`) when a user-visible milestone lands (and sync [README](https://github.com/PurHur/php-compiler/blob/master/README.md) / [index.html](index.html) if the public story changed).

- [North Star 1 (closed #1044)](https://github.com/PurHur/php-compiler/issues/1044) · [bisect follow-up #1750](https://github.com/PurHur/php-compiler/issues/1750)
- [North Star 2 tracker #1492](https://github.com/PurHur/php-compiler/issues/1492)
- [Open issues](https://github.com/PurHur/php-compiler/issues)
- [Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)
- [Living roadmap #78](https://github.com/PurHur/php-compiler/issues/78)

---

[← Back to status overview](index.html)
