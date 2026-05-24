---
layout: status
title: Development status and progress
description: Authoritative snapshot of php-compiler — VM, JIT, AOT web deployment, and self-host bootstrap toward a full PHP compiler.
permalink: /development-status.html
---

*Last updated: May 2026. Edit this file when milestones change; keep in sync with [issue #78](https://github.com/PurHur/php-compiler/issues/78), [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1), [#1056](https://github.com/PurHur/php-compiler/issues/1056) (North Star 2), and the [README](https://github.com/PurHur/php-compiler/blob/master/README.md).*

## At a glance

| | |
|---|---|
| **Try it** | `git clone` → `composer install` → `./phpc test --fast` → [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |
| **Overall progress** | ~**50%** toward web-capable + self-hosting compiler (indicative) |
| **Wave 3 (May 2026)** | Language **10/13** · Stdlib **12/13** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **North Star 1** | Reference web app — VM ✅ · AOT link ✅ · AOT execute **partial** ([#1044](https://github.com/PurHur/php-compiler/issues/1044)) |
| **North Star 2** | Self-compile — M0–M1 ✅ · M2 spine **301/532** · M5 ⬜ ([#1056](https://github.com/PurHur/php-compiler/issues/1056)) |
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
| **Language** (OOP, types, CFG) | ~74% | VM/JIT OOP largely works; wave-3 language **10/13** on master; open: attributes ([#1354](https://github.com/PurHur/php-compiler/issues/1354)), readonly ([#1360](https://github.com/PurHur/php-compiler/issues/1360) / [#1473](https://github.com/PurHur/php-compiler/pull/1473)) |
| **Stdlib** | ~58% | Wave-3 batch ([#1367](https://github.com/PurHur/php-compiler/issues/1367)–[#1379](https://github.com/PurHur/php-compiler/issues/1379)): 12/13 closed; `debug_backtrace` ([#1378](https://github.com/PurHur/php-compiler/issues/1378)) in [#1404](https://github.com/PurHur/php-compiler/pull/1404) |
| **Web AOT** (build, deploy) | ~65% | Project link ✅; home-route execute ✅; PATH_INFO / layout chain 🚧 |
| **Reference app** (MiniWebApp) | ~55% | VM ✅; AOT link ✅; AOT execute **partial** |
| **Self-host** (North Star 2, M0–M5) | ~75% | M0–M1 ✅; M2 spine **438/586**; M3 partial (native run ✅, Zend emit); M4–M5 ⬜ — [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md) |

**Overall (indicative): ~52%** toward the stated north stars below.

**Roadmap wave 3** ([#1380](https://github.com/PurHur/php-compiler/issues/1380)): tracker in repo [docs/roadmap-wave3.md](https://github.com/PurHur/php-compiler/blob/master/docs/roadmap-wave3.md).

Per-builtin and per-construct coverage detail is in generated contributor matrices in the repo (not published or linked from this site).

---

## Two north stars

Work is prioritized along **two parallel tracks**:

### 1. Web-app north star {#north-star-1-web-app}

**Living tracker:** [#1044](https://github.com/PurHur/php-compiler/issues/1044) · **Roadmap:** [#78](https://github.com/PurHur/php-compiler/issues/78) · **Gate ladder:** [#472](https://github.com/PurHur/php-compiler/issues/472)

#### Vision (definition of done)

Compile and run a **normal small PHP web application** end-to-end **without Zend PHP at runtime**:

- Multiple PHP files (`public/index.php`, classes, `config.php`, templates)
- **CGI-style superglobals** (`$_GET`, `$_POST`, `$_SERVER`, …) for request dispatch
- **HTML templates** via `include` / partials (not a single `echo` script)
- Optional **HTTP headers**, form POST, and a **JSON API** route
- **`phpc build --project`** → native binary; **`phpc deploy`** → dist tree for CGI/FastCGI ([#609](https://github.com/PurHur/php-compiler/issues/609), [#718](https://github.com/PurHur/php-compiler/issues/718))
- **Parity:** native `.phpc/bin/app` output matches `phpc serve` / VM for the reference route matrix

This is **North Star 1**. It is **orthogonal** to [North Star 2 (self-host)](#north-star-2-self-host) — the compiler compiling its own `lib/` tree.

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
| **AOT execute** | ✅ **query + PATH_INFO** | Home, hello, contact, api/status via CLI + CGI ([#747](https://github.com/PurHur/php-compiler/issues/747), [#764](https://github.com/PurHur/php-compiler/issues/764)); layout-chain edges remain in bisect ladder |
| **AOT HTTP / deploy 003** | 🚧 **partial** | `MiniWebAppServeAotTest` default-on in full CI ([#1524](https://github.com/PurHur/php-compiler/issues/1524), [#1067](https://github.com/PurHur/php-compiler/issues/1067)); shell `--aot` curls opt-in ([#833](https://github.com/PurHur/php-compiler/issues/833)) |

Examples **000–002** and **004** already pass VM + AOT link + AOT execute. **003** is the integration stress test for real web apps.

#### CI gate ladder (stages 0 → 4d)

Run `./script/miniwebapp-gates.sh` or `phpc doctor --gates`. Details: [miniwebapp-gates.md](https://github.com/PurHur/php-compiler/blob/master/docs/miniwebapp-gates.md) (repo only).

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

#### AOT execute bisect ladder (after #764 home fix)

Smallest reproducers first — see [#78](https://github.com/PurHur/php-compiler/issues/78) bisect table:

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

**Living tracker:** [#1056](https://github.com/PurHur/php-compiler/issues/1056) · **Roadmap:** [#78](https://github.com/PurHur/php-compiler/issues/78) · **Process:** [#1025](https://github.com/PurHur/php-compiler/issues/1025)

#### Vision (definition of done)

The **compiler fully compiles itself** — the stretch goal behind every bootstrap milestone:

- Native **`phpc` / compiler binary** built from php-compiler `lib/` + policy-selected `ext/` (**no `vendor/`**)
- Zend PHP used only for the **last** bootstrap link step, then retired from the loop
- Self-hosted binary runs **real** `bin/compile.php` / `bin/vm.php` driver paths (not stub-only `echo` demos)
- Self-hosted binary compiles **`examples/000-HelloWorld`** without Zend
- Self-hosted binary compiles the **next** compiler revision — **bootstrap loop closed**
- Honest AOT bundle toward the full `bin/vm.php` inventory (**586** files), with `PHP_COMPILER_SELFHOST_AOT` stub surface **shrinking** as lowering lands

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
| **M2 — Lib spine growth** | `compiler_lib_spine_smoke` bundle toward full `bin/vm.php` inventory (**586** files) | 🚧 **438** / 586 units (~75%; [#1056](https://github.com/PurHur/php-compiler/issues/1056), [#1744](https://github.com/PurHur/php-compiler/issues/1744), [#1725](https://github.com/PurHur/php-compiler/issues/1725)) |
| **M3 — Native compiles PHP** | Self-hosted bundle links; HelloWorld AOT **runs** natively; **emit still uses Zend** `bin/compile.php` fallback | 🚧 partial ([#1056](https://github.com/PurHur/php-compiler/issues/1056)) |
| **M4 — Bootstrap loop** | Native toolchain rebuilds the **next** compiler sources (same tree, new revision) | ⬜ |
| **M5 — Full self-host** | Real `bin/vm.php` / `bin/compile.php` path on full inventory; **no Zend bootstrap** | ⬜ **north star** ([#1056](https://github.com/PurHur/php-compiler/issues/1056)) |

#### Scale (why M5 is big)

| Set | ~Files | Notes |
|-----|--------|-------|
| `compiler_minimal` bundle (M0) | **109** | Literal `require_once` closure |
| `compiler_lib_spine_smoke` (M2) | **438** | vm.php-path lib/ + ext/standard growth bundle |
| Top-level `lib/*.php` | **14** | Per-file AOT lint ✅ |
| Full vm.php inventory | **586** | Phase A inventory (`php script/bootstrap-inventory.php`) |

**M2 → M5 gap:** ~**152** inventory files still outside the honest native bundle; plus native compile driver (`parseAndCompile` emit, [#1402](https://github.com/PurHur/php-compiler/issues/1402)); vendor prelink ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

#### Critical path to “compiler compiles itself”

1. **Close M3** — native emit (`BOOTSTRAP_M3_HELLOWORLD_STRICT=1`); expand real lowering on compile spine ([#1402](https://github.com/PurHur/php-compiler/issues/1402))
2. **Finish M2** — spine → full inventory (or honest closure); optional `src/cli.php` ([#1467](https://github.com/PurHur/php-compiler/issues/1467))
3. **M4** — native binary rebuilds the next compiler revision
4. **M5** — vendor prelink + stub retirement; real `bin/vm.php` / `bin/compile.php` without Zend cold boot

Details: [self-host-target.md](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md).

### Bootstrap phases (repo scripts)

| Phase | Command / doc | Status |
|-------|---------------|--------|
| **A — Inventory** | `php script/bootstrap-inventory.php --check` | ✅ **586** files; 0 source blockers |
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
| M2 spine native link | `BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke` | ✅ `compiler_lib_spine_smoke bundle OK` (**438** units) |
| M3 HelloWorld probe | `make bootstrap-selfhost-helloworld` | 🚧 partial — native **run** ✅; emit Zend fallback |
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
| **3 — Web AOT** | `phpc build --project`, deploy, runtime includes | Link ✅; execute partial ([North Star 1](#north-star-1-web-app)) |
| **4 — Polish** | MiniWebApp gates, HTTP smokes, doc sync | Active |

**Current active phase:** Phase 4 polish (AOT execute matrix + HTTP smokes) while self-host waves continue in parallel.

---

## Shipped examples (000–004)

| Example | VM | AOT link | AOT execute |
|---------|----|----------|-------------|
| 000–002, 004 | ✅ | ✅ | ✅ |
| 003-MiniWebApp | ✅ | ✅ | 🚧 partial (home ✅; [#676](https://github.com/PurHur/php-compiler/issues/676)) |

Commands: `./phpc run`, `./phpc build`, `./phpc serve`, `make examples-aot-smoke` (see [README](https://github.com/PurHur/php-compiler/blob/master/README.md)).

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
| [#676](https://github.com/PurHur/php-compiler/issues/676) | North Star 1 execute | Unskip MiniWebApp PHPUnit + shell smokes |
| [#878](https://github.com/PurHur/php-compiler/issues/878)–[#849](https://github.com/PurHur/php-compiler/issues/849) | AOT bisect | Layout/includes/superglobals in native 003 |
| [#828](https://github.com/PurHur/php-compiler/issues/828) | Self-host JIT | `Object_.php` external property children |
| [#84](https://github.com/PurHur/php-compiler/issues/84) | Self-host tree | Full `lib/` bundle growth + namespaces |
| [#57](https://github.com/PurHur/php-compiler/issues/57) | Self-host runtime | Try/catch unwind in native bundle |
| M3–M5 | North Star 2 | Native PHP → rebuild compiler → full tree ([#1056](https://github.com/PurHur/php-compiler/issues/1056)) |

---

## How to contribute

1. **Try the project:** [GETTING-STARTED.md](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) (clone, demo script, `phpc` cheat sheet).
2. Pick an issue by [phase label](https://github.com/PurHur/php-compiler/issues), [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1), [#1056](https://github.com/PurHur/php-compiler/issues/1056) (North Star 2), or self-host bootstrap issues.
3. For self-host work, run `./script/bootstrap-wave-check.sh` before opening a PR.
4. Update **this file** (`docs/pages/development-status.md`) when a user-visible milestone lands (and sync [README](https://github.com/PurHur/php-compiler/blob/master/README.md) / [index.html](index.html) if the public story changed).

- [North Star 1 tracker #1044](https://github.com/PurHur/php-compiler/issues/1044)
- [North Star 2 tracker #1056](https://github.com/PurHur/php-compiler/issues/1056)
- [Open issues](https://github.com/PurHur/php-compiler/issues)
- [Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)
- [Living roadmap #78](https://github.com/PurHur/php-compiler/issues/78)

---

[← Back to status overview](index.html)
