---
layout: status
title: Development status
description: High-level snapshot of php-compiler — VM, AOT web apps, and self-host bootstrap progress.
permalink: /development-status.html
---

*Last updated: 29 May 2026 (`master` @ 03c44c5f) · Tracker: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Roadmap: [#78](https://github.com/PurHur/php-compiler/issues/78)*

## At a glance

| | |
|---|---|
| **What it is** | PHP → CFG → VM / LLVM JIT → AOT native binaries |
| **North star** | Compiler compiles itself without Zend ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **Wave 3** | Language **12/12** · Stdlib **13/13** on master ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **Examples 000–009** | VM + AOT regression fixtures (not a product north star) |
| **Try it** | [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |

[← Overview](index.html) · [**Missing implementation tables**](missing-implementation.html) · [**PHP capability comparison**](capability-comparison.html) · [GitHub](https://github.com/PurHur/php-compiler)

---

## What works today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`
- **Examples 000–009** — VM and AOT link/execute for the curated web subset
- **Self-host M0–M2** — minimal bundle ✅; spine native link **726** / **726** ✅ ([#2868](https://github.com/PurHur/php-compiler/issues/2868), [#1960](https://github.com/PurHur/php-compiler/issues/1960), [#2543](https://github.com/PurHur/php-compiler/issues/2543), [#2652](https://github.com/PurHur/php-compiler/issues/2652))
- **Self-host M3 (smoke native)** — HelloWorld strict **`emit_path=native`** ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493), [#2610](https://github.com/PurHur/php-compiler/issues/2610), [#2618](https://github.com/PurHur/php-compiler/issues/2618)); emit TU routes **`compileEmitSmoke`** through Compiler spine ([#2667](https://github.com/PurHur/php-compiler/pull/2667)); inventory **compile-smoke** / native **`bin/compile.php`** emit still 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)).
- **Self-host M4 (gen-2→gen-3)** — gen-2→gen-3 **726/726** spine recompile **without Zend on the compile step** ✅ ([#2697](https://github.com/PurHur/php-compiler/pull/2697), [#2866](https://github.com/PurHur/php-compiler/issues/2866)); `make bootstrap-loop-gen2-recompile-spine` → `compiler_lib_spine_smoke bundle OK`. Full revision probe (`make bootstrap-selfhost-full-revision-probe`) ✅ — inventory argv driver compiles `bin/compile.php` → gen-3 ([#2880](https://github.com/PurHur/php-compiler/issues/2880), [#3046](https://github.com/PurHur/php-compiler/issues/3046)).
- **Self-host M5 (partial)** — vendor prelink **3/3** `object_ok` (cfg, types, llvm) ✅ ([#3036](https://github.com/PurHur/php-compiler/pull/3036), [#1416](https://github.com/PurHur/php-compiler/issues/1416)); committed `.o` cold boot without `vendor/` ✅; default bootstrap still uses Zend for `bin/compile.php` when `build/` is empty — presenter `make north-star5-verify`.

**Not claimed:** full Zend PHP compatibility (subset compiler only).

---

## North star ladder

| Milestone | Status |
|-----------|--------|
| **M0** — Small `lib/` bundle runs | ✅ |
| **M1** — Compiler-shaped bundle + compile-smoke | ✅ |
| **M2** — Spine toward full inventory | ✅ **726/726** link (complete) |
| **M3** — Native compiles PHP (no Zend emit) | 🚧 HelloWorld strict ✅ · compile-smoke / `bin/compile.php` emit 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)) |
| **M4** — Bootstrap loop (next revision) | ✅ gen-2→gen-3 spine **726/726** (native argv `-o`) · full revision `bin/compile.php` probe ✅ |
| **M5** — Full self-host, no `vendor/` cold boot | 🚧 vendor **3/3** prelinked ✅ · Zend cold boot for `bin/compile.php` ⬜ |

**Critical path:** native `bin/compile.php` emit (full revision probe) → retire Zend on empty `build/` bootstrap ([#1416](https://github.com/PurHur/php-compiler/issues/1416), [#1492](https://github.com/PurHur/php-compiler/issues/1492)).

**Verified 29 May 2026** (`master` @ 03c44c5f, Docker `php-compiler:22.04-dev`, LLVM 9): `bootstrap-loop-gen2-recompile-spine` (gen-3 **726/726**, no Zend on compile); `bootstrap-selfhost-full-revision-probe` (inventory argv `bin/compile.php` → gen-3); `north-star5-verify` (vendor **3/3**, committed `.o` cold boot).

Contributor detail: [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md), [`docs/bootstrap-generations.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-generations.md), [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md).

---

## What is still missing

For tables of real implementation gaps (emit spine, LLVM deny list, deferred spine paths, language subset), see **[missing-implementation.html](missing-implementation.html)**.

For tracked PHP language/stdlib features vs VM / JIT / AOT (from the capability matrices), see **[capability-comparison.html](capability-comparison.html)**.

---

## Example integration tests

`examples/003-MiniWebApp` through **009-FastCGIWeb** exercise routing, templates, sessions, uploads, throws, and deploy smoke in CI. They are **regression harnesses**, not the project north star ([#1044](https://github.com/PurHur/php-compiler/issues/1044) closed).

---

## Shipped examples (000–009)

Representative gates (defaults from `script/ci-defaults.env`): **`BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE`** (M3 compile-smoke probe), **`FILE_UPLOAD_WEB_SMOKE_GATE=1`**, **`FILE_UPLOAD_WEB_AOT_LINK_GATE=1`**, **`FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1`**, **`THROWS_WEB_SMOKE_GATE=1`**.

| Example | Highlights |
|---------|-------------|
| 006-FileUploadWeb | Multipart uploads + presenters ([#1999](https://github.com/PurHur/php-compiler/issues/1999), [#2039](https://github.com/PurHur/php-compiler/issues/2039)); `FILE_UPLOAD_WEB_SMOKE_GATE` / `FILE_UPLOAD_WEB_AOT_LINK_GATE` / `FILE_UPLOAD_WEB_AOT_SMOKE_GATE`. |
| 007-ThrowsWeb | Invalid POST + `catch` ([#2076](https://github.com/PurHur/php-compiler/issues/2076)); `THROWS_WEB_SMOKE_GATE` smoke ([#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2125](https://github.com/PurHur/php-compiler/issues/2125)); **007-ThrowsWeb** presenters ([#2145](https://github.com/PurHur/php-compiler/issues/2145)). |

---

## Contribute

1. Clone and run `./phpc test --fast`, then see [GETTING-STARTED](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md).
2. Pick work from [#1492](https://github.com/PurHur/php-compiler/issues/1492) or [open issues](https://github.com/PurHur/php-compiler/issues).
3. Update **`docs/pages/development-status.md`**, **`index.html`**, and **`missing-implementation.html`** when a user-visible gap closes.

[Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)

---

[← Back to overview](index.html)
