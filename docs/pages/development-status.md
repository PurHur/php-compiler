---
layout: status
title: Development status and progress
description: Authoritative written snapshot of php-compiler milestones, phases, and gaps toward a full implementation.
permalink: /development-status.html
---

*Last updated: May 2026. Edit this file when milestones change; keep in sync with [issue #78](https://github.com/PurHur/php-compiler/issues/78), [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1), and the [README](https://github.com/PurHur/php-compiler/blob/master/README.md).*

## What this project is

**php-compiler** is a PHP compiler that:

1. Parses PHP into a **control-flow graph** (via [php-cfg](https://github.com/ircmaxell/php-cfg))
2. Lowers the CFG to internal **opcodes** (`lib/Compiler.php`)
3. Executes on a **VM** (`phpc run`, `bin/vm.php`)
4. **JIT-compiles** hot paths to LLVM 9 (`lib/JIT.php`)
5. **AOT-links** whole programs to native binaries (`phpc build`, `bin/compile.php`)

Deployed apps can run **without Zend PHP** at runtime. Development and bootstrap still use system PHP today.

This document is the **public development status** for the project. Technical contributor docs (inventory, capabilities matrices, CI matrices) stay in the [repository `docs/` tree](https://github.com/PurHur/php-compiler/tree/master/docs) and are **not** mirrored on this site.

---

## How complete is the compiler?

Indicative composite toward a **web-capable, self-hosting** compiler (not line-count parity with Zend PHP):

| Area | Progress | Summary |
|------|----------|---------|
| **Foundation** (CI, CLI, Docker) | ~88% | `phpc` CLI, local/Docker CI, bootstrap GHA workflow |
| **Language** (OOP, types, CFG) | ~62% | VM/JIT OOP largely works; gaps in typed arrays, `::class`, some returns |
| **Stdlib** | ~55% | Large batches of JIT builtins; many functions VM-only |
| **Web AOT** (build, deploy) | ~65% | Project link ✅; home-route execute ✅; PATH_INFO / layout chain 🚧 |
| **Reference app** (MiniWebApp) | ~55% | VM ✅; AOT link ✅; AOT execute **partial** |
| **Self-host bootstrap** | ~35% | M0 ✅; M1 🚧; M2 ⬜ |

**Overall (indicative): ~45%** toward the stated north stars below.

For per-function truth, see the generated [capabilities matrix](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md) in the repo.

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
| **AOT execute** | 🚧 **partial** | Home `?route=home` ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764) closed, [#1040](https://github.com/PurHur/php-compiler/pull/1040)); hello / contact / PATH_INFO / layout chain still open |
| **AOT HTTP / deploy 003** | ⬜ | Blocked on execute matrix ([#676](https://github.com/PurHur/php-compiler/issues/676), [#833](https://github.com/PurHur/php-compiler/issues/833), [#612](https://github.com/PurHur/php-compiler/issues/612)) |

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
| 4b2 | AOT execute (PHPUnit) | opt-in `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#791](https://github.com/PurHur/php-compiler/issues/791)) |
| 4c | `examples-aot-smoke` 003 slice | ❌ ([#881](https://github.com/PurHur/php-compiler/issues/881)) |
| 4d | Deploy smoke (003) | 001/002 only ([#718](https://github.com/PurHur/php-compiler/issues/718)) |

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
```

#### What is out of scope (for this north star)

- Full Zend PHP compatibility (see [capabilities.md](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md))
- Sessions/auth stacks, databases, Composer autoload at runtime
- Self-hosting the compiler ([North Star 2](#north-star-2-self-host))

### 2. Self-host north star {#north-star-2-self-host}

Use php-compiler to build a native binary from its own **`lib/`** tree (no `vendor/`), then use that binary to compile PHP again.

- **Tracking:** [#78](https://github.com/PurHur/php-compiler/issues/78) (roadmap), [#212](https://github.com/PurHur/php-compiler/issues/212) (umbrella), [#1025](https://github.com/PurHur/php-compiler/issues/1025) (process)
- **Contributor deep dive:** [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md) (repo only)

**What “self-host” means here:** Zend PHP still runs `bin/compile.php` during bootstrap. The output is a **curated, stub-tolerant** native bundle — not yet a replacement for system PHP.

---

## Self-host milestone ladder {#self-host-milestone-ladder}

| Milestone | Meaning | Status |
|-----------|---------|--------|
| **M0 — Bundled subset runs** | ~109 `require_once` units in `test/selfhost/compiler_minimal/main.php` → `build/selfhost` prints `compiler_minimal bundle OK` | ✅ [#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913) |
| **M1 — Compiler compiles itself** | Bundled `Compiler.php` AOT lint; compile-smoke native link + AOT echo (`compiler smoke`); driver smoke toward `bin/compile.php` | 🚧 in progress |
| **M2 — Full `lib/` self-host** | `bin/compile.php -o` on real `lib/`; self-hosted binary re-invokes compiler without Zend | ⬜ open |

### Automated bootstrap gates (M0 / M1)

| Gate | Command | Status |
|------|---------|--------|
| Inventory | `php script/bootstrap-inventory.php --check` | ✅ ~413 files on vm.php path, 0 blockers |
| Lib AOT lint | `php bin/compile.php -l lib/*.php` | ✅ 14/14 top-level `lib/*.php` |
| Bundled compiler lint | `./script/bootstrap-selfhost-lint.sh` | ✅ |
| Native link + run | `./script/bootstrap-selfhost-link.sh` | ✅ M0 |
| Compile smoke link | `make bootstrap-selfhost-compile-smoke` | ✅ |
| Compile smoke AOT echo | `make bootstrap-selfhost-compile-smoke-run` | ✅ M1 partial |
| Wave gate | `./script/bootstrap-wave-check.sh` | ✅ in CI / GHA |

With `PHP_COMPILER_SELFHOST_AOT=1`, many `Compiler` / `JIT` / VM paths use **LLVM stubs** so the bundle links; `SelfHostBuiltinPolicy` keeps ~40 stdlib builtins at real lowering and stubs the rest.

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
| [#767](https://github.com/PurHur/php-compiler/issues/767) | Language | Typed property `array` in VM/JIT/AOT |
| M2 self-host | Bootstrap | Full `lib/` without Zend bootstrap |

---

## How to contribute

1. Pick an issue by [phase label](https://github.com/PurHur/php-compiler/issues), [#1044](https://github.com/PurHur/php-compiler/issues/1044) (North Star 1), or self-host track.
2. For self-host work, run `./script/bootstrap-wave-check.sh` before opening a PR.
3. Update **this file** (`docs/pages/development-status.md`) when a user-visible milestone lands.

- [North Star 1 tracker #1044](https://github.com/PurHur/php-compiler/issues/1044)
- [Open issues](https://github.com/PurHur/php-compiler/issues)
- [Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)
- [Living roadmap #78](https://github.com/PurHur/php-compiler/issues/78)

---

[← Back to status overview](index.html)
