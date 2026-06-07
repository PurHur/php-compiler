---
layout: status
title: Development status
description: High-level snapshot of php-compiler — VM, AOT web apps, language wave-2, and self-host bootstrap progress.
permalink: /development-status.html
---

*Last updated: 3 Jun 2026 (`master` @ cbc7e80c) · Tracker: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Roadmap: [#78](https://github.com/PurHur/php-compiler/issues/78)*

## At a glance

| | |
|---|---|
| **What it is** | PHP → CFG → VM / LLVM JIT → AOT native binaries |
| **North star** | Compiler compiles itself without Zend ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **Wave 3** | Language **12/12** · Stdlib **13/13** on master ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **Spine SSOT** | `php script/bootstrap-spine-count.php` → **1298** / **1681** |
| **Builtin matrix** | **321** functions ([`docs/capabilities.md`](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md)) |
| **Try it** | [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |

[← Overview](index.html) · [**Missing implementation**](missing-implementation.html) · [**PHP capability comparison**](capability-comparison.html) · [GitHub](https://github.com/PurHur/php-compiler)

---

## Recent landings (Jun 2026)

### Bootstrap / php-cfg

| Area | PR | Notes |
|------|-----|-------|
| Union-type overlays | [#5096](https://github.com/PurHur/php-compiler/pull/5096) | Apply overlay when `parseTypeNode` lacks `UnionType` handler — spine parse progress |
| JIT try/catch/finally | [#4264](https://github.com/PurHur/php-compiler/pull/4264) | EH LLVM IR; MCJIT execute still VM fallback ([#2114](https://github.com/PurHur/php-compiler/issues/2114)) |

### Language & compiler (May wave, still current)

| Area | PR / issue | Notes |
|------|------------|-------|
| Closures + arrows | [#3071](https://github.com/PurHur/php-compiler/pull/3071)–[#3108](https://github.com/PurHur/php-compiler/pull/3108) | VM `ClosureState`; JIT `use()` value + **by-ref** |
| Try / catch / finally | [#3081](https://github.com/PurHur/php-compiler/pull/3081), [#3106](https://github.com/PurHur/php-compiler/pull/3106) | VM finally; JIT EH IR verify |
| Inventory emit driver | [#3070](https://github.com/PurHur/php-compiler/pull/3070) | HelloWorld strict **`emit_path=native`** ✅ |

### Still open (high signal)

- **MCJIT execute** — `bin/jit.php -r` SIGSEGV ([#98](https://github.com/PurHur/php-compiler/issues/98))
- **M4 gen-2→gen-3 full-spine recompile** — still 🚧; Zend fallback when native driver blocked
- **Native `bin/compile.php` without thin emit TU** — [#3024](https://github.com/PurHur/php-compiler/issues/3024)

---

## What works today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`, `doctor`
- **Examples 000–009** — VM and AOT link/execute for the curated web subset
- **Self-host M0** — `compiler_minimal bundle OK` ✅ in Docker
- **Self-host M2** — spine **1298** / **1681** 🚧; native spine **link** ✅ when LLVM + patches wired
- **Self-host M3** — HelloWorld + inventory emit strict native ✅; production `bin/compile.php` 🚧 ([#3024](https://github.com/PurHur/php-compiler/issues/3024))
- **Self-host M4** — gen-1 link partial; **gen-2→gen-3 recompile** 🚧 (native driver vs Zend fallback)
- **Self-host M5 (partial)** — vendor prelink **3/3** ✅; Zend still default for empty `build/`

**Not claimed:** full Zend PHP compatibility (subset compiler only).

---

## Shipped examples (000–009)

Shipped examples under `examples/` are **regression fixtures** for VM/JIT/AOT and the web subset (see `examples/README.md`).

| Example | VM | AOT build | Notes / gates |
|---------|----|-----------|--------------|
| 006-FileUploadWeb | ✅ | ✅ | multipart `$_FILES`; gates default-on ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| 007-ThrowsWeb | ✅ | ✅ | throw/catch form smoke ([#2093](https://github.com/PurHur/php-compiler/issues/2093)) |
| 009-FastCGIWeb | ✅ | ✅ | FastCGI execute ([#2351](https://github.com/PurHur/php-compiler/issues/2351)) |

## North star ladder

| Milestone | Status |
|-----------|--------|
| **M0** — Small `lib/` bundle runs | ✅ |
| **M1** — Compiler-shaped bundle + compile-smoke | ✅ |
| **M2** — Spine toward full inventory | 🚧 **1298** / **1681** |
| **M3** — Native compiles PHP (no Zend emit) | 🚧 Smoke + inventory emit ✅ · `bin/compile.php` production emit 🚧 |
| **M4** — Bootstrap loop (next revision) | 🚧 Gen-2→gen-3 full-spine recompile open |
| **M5** — Full self-host, no `vendor/` cold boot | 🚧 |

**Critical path:** native `bin/compile.php` on inventory argv driver ([#3024](https://github.com/PurHur/php-compiler/issues/3024)) → retire Zend on empty `build/` ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

Contributor detail: [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md), [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md).

---

## What is still missing

**[missing-implementation.html](missing-implementation.html)** — emit spine, LLVM deny list, language subset gaps.

**[capability-comparison.html](capability-comparison.html)** — PHP language/stdlib vs VM / JIT / AOT (from capability matrices).

---

## Contribute

**We do not accept GitHub issues or pull requests without prior coordination.** See [CONTRIBUTING.md](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md).

When a user-visible gap closes, update **`development-status.md`**, **`index.html`**, and **`missing-implementation.html`**.

[← Back to overview](index.html)
