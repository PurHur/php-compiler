---
layout: status
title: Development status and progress
description: Authoritative written snapshot of php-compiler milestones, phases, and gaps toward a full implementation.
permalink: /development-status.html
---

*Last updated: May 2026. Edit this file when milestones change; keep in sync with [issue #78](https://github.com/PurHur/php-compiler/issues/78) and the [README](https://github.com/PurHur/php-compiler/blob/master/README.md).*

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
| **Web AOT** (build, deploy) | ~58% | Project link works; complex template execute blocked |
| **Reference app** (MiniWebApp) | ~48% | VM ✅; native execute ❌ ([#764](https://github.com/PurHur/php-compiler/issues/764)) |
| **Self-host bootstrap** | ~35% | M0 ✅; M1 🚧; M2 ⬜ |

**Overall (indicative): ~42%** toward the stated north stars below.

For per-function truth, see the generated [capabilities matrix](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md) in the repo.

---

## Two north stars

Work is prioritized along **two parallel tracks**:

### 1. Web-app north star

Ship a small multi-file PHP web application (forms, headers, templates, optional AOT CGI deploy).

- **Reference app:** [`examples/003-MiniWebApp`](https://github.com/PurHur/php-compiler/tree/master/examples/003-MiniWebApp)
- **VM / `phpc serve`:** ✅
- **AOT link (`phpc build --project`):** ✅ ([#752](https://github.com/PurHur/php-compiler/issues/752))
- **AOT execute (native routes):** ❌ blocked by [#764](https://github.com/PurHur/php-compiler/issues/764) — binary links but home/hello routes return empty stdout

Examples **000–002** and **004** fully pass VM + AOT link + AOT execute smokes. **003** links but execute is gated until #764 is fixed.

### 2. Self-host north star

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
| **3 — Web AOT** | `phpc build --project`, deploy, runtime includes | Link ✅; execute #764 |
| **4 — Polish** | MiniWebApp gates, HTTP smokes, doc sync | Active |

**Current active phase:** Phase 4 polish (AOT execute + HTTP) while self-host waves continue in parallel.

---

## Shipped examples (000–004)

| Example | VM | AOT link | AOT execute |
|---------|----|----------|-------------|
| 000–002, 004 | ✅ | ✅ | ✅ |
| 003-MiniWebApp | ✅ | ✅ | ❌ (#764) |

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
| [#764](https://github.com/PurHur/php-compiler/issues/764) | AOT execute | MiniWebApp native routes empty stdout |
| [#828](https://github.com/PurHur/php-compiler/issues/828) | JIT | Self-host `Object_.php` external property children |
| [#767](https://github.com/PurHur/php-compiler/issues/767) | Language | Typed property `array` in VM/JIT/AOT |
| M2 self-host | Bootstrap | Full `lib/` without Zend bootstrap |

---

## How to contribute

1. Pick an issue by [phase label](https://github.com/PurHur/php-compiler/issues) or north-star track.
2. For self-host work, run `./script/bootstrap-wave-check.sh` before opening a PR.
3. Update **this file** (`docs/pages/development-status.md`) when a user-visible milestone lands.

- [Open issues](https://github.com/PurHur/php-compiler/issues)
- [Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)
- [Living roadmap #78](https://github.com/PurHur/php-compiler/issues/78)

---

[← Back to status overview](index.html)
