---
layout: status
title: Development status
description: High-level snapshot of php-compiler — VM, AOT web apps, and self-host bootstrap progress.
permalink: /development-status.html
---

*Last updated: May 2026 · Tracker: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Roadmap: [#78](https://github.com/PurHur/php-compiler/issues/78)*

## At a glance

| | |
|---|---|
| **What it is** | PHP → CFG → VM / LLVM JIT → AOT native binaries |
| **North star** | Compiler compiles itself without Zend ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **Wave 3** | Language **12/12** · Stdlib **13/13** on master ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **Examples 000–009** | VM + AOT regression fixtures (not a product north star) |
| **Try it** | [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |

[← Overview](index.html) · [**Missing implementation tables**](missing-implementation.html) · [GitHub](https://github.com/PurHur/php-compiler)

---

## What works today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`
- **Examples 000–009** — VM and AOT link/execute for the curated web subset
- **Self-host M0–M2** — minimal bundle ✅; spine native link **661/657** ✅ ([#1960](https://github.com/PurHur/php-compiler/issues/1960))
- **Self-host M3 (partial)** — HelloWorld / compile-smoke AOT **runs** natively ✅; **emit** still Zend fallback 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937))

**Not claimed:** full Zend PHP compatibility (subset compiler only).

---

## North star ladder

| Milestone | Status |
|-----------|--------|
| **M0** — Small `lib/` bundle runs | ✅ |
| **M1** — Compiler-shaped bundle + compile-smoke | ✅ |
| **M2** — Spine toward full inventory | ✅ **661/657** link |
| **M3** — Native compiles PHP (no Zend emit) | 🚧 run ✅ · emit 🚧 |
| **M4** — Bootstrap loop (next revision) | ⬜ |
| **M5** — Full self-host, no `vendor/` cold boot | ⬜ |

**Critical path:** close M3 native emit → M4 loop → M5 vendor prelink ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

Contributor detail: [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md), [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md).

---

## What is still missing

For tables of real implementation gaps (emit spine, LLVM deny list, deferred spine paths, language subset), see **[missing-implementation.html](missing-implementation.html)**.

---

## Example integration tests

`examples/003-MiniWebApp` through **009-FastCGIWeb** exercise routing, templates, sessions, uploads, throws, and deploy smoke in CI. They are **regression harnesses**, not the project north star ([#1044](https://github.com/PurHur/php-compiler/issues/1044) closed).

---

## Contribute

1. Clone and run `./phpc test --fast`, then see [GETTING-STARTED](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md).
2. Pick work from [#1492](https://github.com/PurHur/php-compiler/issues/1492) or [open issues](https://github.com/PurHur/php-compiler/issues).
3. Update **`docs/pages/development-status.md`**, **`index.html`**, and **`missing-implementation.html`** when a user-visible gap closes.

[Contributing guide](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md)

---

[← Back to overview](index.html)
