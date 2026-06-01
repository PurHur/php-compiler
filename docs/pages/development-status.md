---
layout: status
title: Development status
description: High-level snapshot of php-compiler — VM, AOT web apps, language wave-2, and self-host bootstrap progress.
permalink: /development-status.html
---

*Last updated: 29 May 2026 (`master` @ 7816b0bc) · Tracker: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Roadmap: [#78](https://github.com/PurHur/php-compiler/issues/78)*

## At a glance

| | |
|---|---|
| **What it is** | PHP → CFG → VM / LLVM JIT → AOT native binaries |
| **North star** | Compiler compiles itself without Zend ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **Wave 3** | Language **12/12** · Stdlib **13/13** on master ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **Language wave (May 2026)** | Closures VM+JIT, try/catch VM+JIT IR, generators VM, stdlib expansion |
| **Builtin matrix** | **321** functions ([`docs/capabilities.md`](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md)) |
| **Try it** | [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |

[← Overview](index.html) · [**Missing implementation**](missing-implementation.html) · [**PHP capability comparison**](capability-comparison.html) · [GitHub](https://github.com/PurHur/php-compiler)

---

## Recent landings (May 2026)

### Language & compiler

| Area | PR / issue | Notes |
|------|------------|-------|
| Closures + arrows | [#3071](https://github.com/PurHur/php-compiler/pull/3071)–[#3108](https://github.com/PurHur/php-compiler/pull/3108) | VM `ClosureState`; JIT `use()` value + **by-ref**; `$arr[0]()` indirect invoke |
| Try / catch / finally | [#3081](https://github.com/PurHur/php-compiler/pull/3081), [#3106](https://github.com/PurHur/php-compiler/pull/3106), [#3107](https://github.com/PurHur/php-compiler/pull/3107) | VM finally + return-through-finally; JIT EH **IR verify**; MCJIT execute still VM fallback ([#2114](https://github.com/PurHur/php-compiler/issues/2114)) |
| Generators | [#3085](https://github.com/PurHur/php-compiler/pull/3085) | Keyed yield; `Block::requiresVmLowering` SSOT |
| `parent::class` / `$prop` | [#3136](https://github.com/PurHur/php-compiler/pull/3136) | VM + JIT |
| Backed enums | [#3091](https://github.com/PurHur/php-compiler/pull/3091) | php-cfg patch drift fix |
| Intersection AOT | [#3103](https://github.com/PurHur/php-compiler/pull/3103) | Call-site checks |

### Standard library (sample)

`class_uses`, `class_alias`, `get_debug_type`, `iterator_to_array`, `array_chunk` (preserve keys), `settype`, `array_replace_recursive`, `json_validate` (VM), `preg_last_error_msg` (VM), `fdiv`, **DateTime** / **DateTimeZone** — see merged PRs **#3104**–**#3138**.

Closure callbacks in **`array_map` / `array_filter` / `usort`** on VM ([#3086](https://github.com/PurHur/php-compiler/pull/3086)).

### Self-host / M3

| Item | PR | Notes |
|------|-----|-------|
| Inventory emit driver | [#3070](https://github.com/PurHur/php-compiler/pull/3070) | `BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1` HelloWorld strict **native** ✅ |
| Parse failure diagnostics | [#3084](https://github.com/PurHur/php-compiler/pull/3084) | Native vendor invoker surfaces compile errors ([#3037](https://github.com/PurHur/php-compiler/issues/3037)) |

### Still open (high signal)

- **MCJIT execute** — `bin/jit.php -r` SIGSEGV in harness ([#98](https://github.com/PurHur/php-compiler/issues/98)); embed runtime partial on master
- **Generator / enum AOT** — [#3074](https://github.com/PurHur/php-compiler/issues/3074), [#3076](https://github.com/PurHur/php-compiler/issues/3076)
- **Native `bin/compile.php` without thin emit TU** — [#3024](https://github.com/PurHur/php-compiler/issues/3024)

---

## What works today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`, `doctor`
- **Examples 000–009** — VM and AOT link/execute for the curated web subset
- **Self-host M0–M2** — minimal bundle ✅; spine native link **834**/**1230** 🚧
- **Self-host M3** — HelloWorld strict **`emit_path=native`** ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)); thin TU + **inventory emit** strict ✅ ([#3070](https://github.com/PurHur/php-compiler/pull/3070)); production **`bin/compile.php`** inventory emit 🚧 ([#3024](https://github.com/PurHur/php-compiler/issues/3024))
- **Self-host M4** — gen-2→gen-3 **834**/**1230** without Zend on compile ✅; full revision probe ✅ ([#3058](https://github.com/PurHur/php-compiler/pull/3058))
- **Self-host M5 (partial)** — vendor prelink **3/3** ✅; committed `.o` cold boot ✅; gen-0 seed ✅; Zend still used for empty `build/` bootstrap

**Not claimed:** full Zend PHP compatibility (subset compiler only).

---

## North star ladder

| Milestone | Status |
|-----------|--------|
| **M0** — Small `lib/` bundle runs | ✅ |
| **M1** — Compiler-shaped bundle + compile-smoke | ✅ |
| **M2** — Spine toward full inventory | 🚧 **834**/**1230** |
| **M3** — Native compiles PHP (no Zend emit) | 🚧 HelloWorld strict ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)) · inventory emit ✅ · compile-smoke 🚧 ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); gate `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1` |
| **M4** — Bootstrap loop (next revision) | ✅ |
| **M5** — Full self-host, no `vendor/` cold boot | 🚧 |

**Critical path:** native `bin/compile.php` on inventory argv driver without dedicated thin emit TU ([#3024](https://github.com/PurHur/php-compiler/issues/3024)) → retire Zend on empty `build/` ([#1416](https://github.com/PurHur/php-compiler/issues/1416)).

Contributor detail: [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md), [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md).

---

## Shipped examples (000–009)

| Example | VM | JIT | AOT build | Notes |
|---------|----|-----|-----------|-------|
| 003-MiniWebApp | ✅ | partial | ✅ | Router + PATH_INFO; native execute ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764)) |
| 005-SessionsWeb | ✅ | ✅ | ✅ | Sessions; deploy smoke opt-in ([#1893](https://github.com/PurHur/php-compiler/issues/1893)) |
| 006-FileUploadWeb | ✅ | ✅ | ✅ | Multipart uploads; `FILE_UPLOAD_WEB_SMOKE_GATE=1` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) · `FILE_UPLOAD_WEB_AOT_LINK_GATE=1` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)) · `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) |
| 007-ThrowsWeb | ✅ | ✅ | ✅ | Form validation throw/catch; `THROWS_WEB_SMOKE_GATE=1` ([#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2101](https://github.com/PurHur/php-compiler/issues/2101)) |
| 008-SelfHostProbe | ✅ | — | ✅ | North star presenter ([#2207](https://github.com/PurHur/php-compiler/issues/2207)) |
| 009-FastCGIWeb | ✅ | 📋 deferred | ✅ | FastCGI adapter ([#173](https://github.com/PurHur/php-compiler/issues/173)); diagnostics ([#2331](https://github.com/PurHur/php-compiler/issues/2331)); `FASTCGI_WEB_SMOKE_GATE=1` |

## What is still missing

**[missing-implementation.html](missing-implementation.html)** — emit spine, LLVM deny list, language subset gaps.

**[capability-comparison.html](capability-comparison.html)** — PHP language/stdlib vs VM / JIT / AOT (from capability matrices).

---

## Contribute

**We do not accept GitHub issues or pull requests without prior coordination.** See [CONTRIBUTING.md](https://github.com/PurHur/php-compiler/blob/master/CONTRIBUTING.md).

When a user-visible gap closes, update **`development-status.md`**, **`index.html`**, and **`missing-implementation.html`**.

[← Back to overview](index.html)
