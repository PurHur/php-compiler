---
layout: status
title: Development status
description: High-level snapshot of php-compiler — VM, AOT web apps, language wave-2, and self-host bootstrap progress.
permalink: /development-status.html
---

*Last updated: 21 Jun 2026 (`master` @ v1.1.0 prep) · Tracker: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Roadmap: [#78](https://github.com/PurHur/php-compiler/issues/78)*

## At a glance

| | |
|---|---|
| **What it is** | PHP → CFG → VM / LLVM JIT → AOT native binaries |
| **North star** | Compiler compiles itself without Zend ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| **v1.1.0 theme** | M5 fast-path stability; enum/property hooks; `preg_match` JIT; `spl_autoload*`; php-in-PHP JIT helpers ([#78](https://github.com/PurHur/php-compiler/issues/78)) |
| **Wave 3** | Language **6596/6596** · Stdlib **6596/6596** on master ([#1380](https://github.com/PurHur/php-compiler/issues/1380)) |
| **Spine SSOT** | `php script/bootstrap-spine-count.php` → **7288** / **7290** (2 deferred: #24115, #27103) |
| **Builtin matrix** | **1555** functions ([`docs/capabilities.md`](https://github.com/PurHur/php-compiler/blob/master/docs/capabilities.md)) |
| **Try it** | [`docs/GETTING-STARTED.md`](https://github.com/PurHur/php-compiler/blob/master/docs/GETTING-STARTED.md) |

[← Overview](docs/pages/index.html) · [**Missing implementation**](docs/pages/missing-implementation.html) · [**PHP capability comparison**](docs/pages/capability-comparison.html) · [GitHub](https://github.com/PurHur/php-compiler)

---

## Recent landings (Jun 2026)

### Bootstrap / self-host

| Area | PR / commit | Notes |
|------|-------------|-------|
| M4 full ladder | `make bootstrap-loop-probe` | Gen-1→gen-2 native + gen-2→gen-3 full spine + full-revision argv ✅ |
| M5 spine runtime + bootstrap | `fix/spine-aot-jit-blockers` | Native bundle-OK probe; inventory argv spine-lint fallback; gen-0 sidecars refreshed ([#8559](https://github.com/PurHur/php-compiler/issues/8559)) |
| VM driver execute probe | [9493e806d](https://github.com/PurHur/php-compiler/commit/9493e806d) | **~20ms** feedback loop — no full relink on stale SHA; `BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1` for rebuild ([#2201](https://github.com/PurHur/php-compiler/issues/2201)) |
| M5 presenter | `make north-star5-verify-fast` | Daily PR gate (~1–2 min); `--strict` (~1h) pre-merge only ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| Gen-0 refresh | [a8cffaa0e](https://github.com/PurHur/php-compiler/commit/a8cffaa0e) | Spine runtime probe + gen-0 sidecars (**7288/7290**); inventory argv spine-lint fallback ([#8559](https://github.com/PurHur/php-compiler/issues/8559)) |
| Parallel compile jobs | [#10347](https://github.com/PurHur/php-compiler/pull/10347) | `PHP_COMPILER_COMPILE_JOBS` for vendor prelink + M3 sidecar warmup |
| Spine coverage | [#10356](https://github.com/PurHur/php-compiler/pull/10356) | `GeneratorYieldSourceMarker.php` in spine; inventory **3276** Phase A files |
| v1.1.0 docs sync | [#10369](https://github.com/PurHur/php-compiler/issues/10369) | README, development-status, examples README, capability matrix (**1555** builtins) |

### Stdlib / php-in-php (Jun wave)

| Area | PR | Notes |
|------|-----|-------|
| mb_strwidth / mb_strimwidth | [#8497](https://github.com/PurHur/php-compiler/pull/8497) | JIT/AOT lowering + spine sync |
| bcmath phase 2 | [#8491](https://github.com/PurHur/php-compiler/pull/8491) | `bcmod` / `bcpow` / `bcsqrt` |
| php:// I/O streams | [#8493](https://github.com/PurHur/php-compiler/pull/8493) | Native `php://input` / `php://output` without host `@fopen` |
| VmFsWriteNative | [#8488](https://github.com/PurHur/php-compiler/pull/8488) | `file_put_contents` VM path |

### Still open (high signal)

- **MCJIT execute** — `bin/jit.php -r` SIGSEGV ([#98](https://github.com/PurHur/php-compiler/issues/98))
- **Literal spine ratio** — **7288/7290** (2 deferred: #24115, #27103) ✅ (Jul 2026)
- **Compile-spine stub retirement** — shrink `PHP_COMPILER_SELFHOST_AOT` on M3 allowlist ([#1402](https://github.com/PurHur/php-compiler/issues/1402))
- **007-ThrowsWeb AOT execute** — invalid POST segfault at runtime (link OK; slice `EXAMPLES_AOT_SMOKE_ONLY=007`)
- **LLVM 14+ upgrade** — experimental `script/install-llvm14.sh` ([#174](https://github.com/PurHur/php-compiler/issues/174))

---

## What works today

- **`phpc` CLI** — `run`, `serve`, `build`, `deploy`, `lint`, `test`, `init`, `doctor`
- **Examples 000–009** — VM and AOT link/execute for the curated web subset
- **Self-host M0** — `compiler_minimal bundle OK` ✅
- **Self-host M2** — spine **7288/7290** (2 deferred: #24115, #27103) ✅; native link + lint ✅
- **Self-host M3** — HelloWorld strict `emit_path=native` ✅ ([#1493](https://github.com/PurHur/php-compiler/issues/1493)); inventory argv `bin/compile.php` ✅ ([#3024](https://github.com/PurHur/php-compiler/issues/3024) closed); compile-smoke strict native ✅ ([#1937](https://github.com/PurHur/php-compiler/issues/1937))
- **Self-host M4** — `make bootstrap-loop-probe` full ladder ✅; gen-2→gen-3 full-spine recompile ✅
- **Self-host M3–M5** — vendor prelink **3/3** ✅; **`make north-star5-verify-fast`** daily ✅; VM probe ~**20ms**. **`--strict` red** ([#21417](https://github.com/PurHur/php-compiler/issues/21417)) and **M3/M4 emit paths are prelinked blob COPIES, not native compiles** ([#21860](https://github.com/PurHur/php-compiler/issues/21860)) — the byte-identical gen-0/gen-2/gen-3 result follows from copying and is not fixpoint evidence

**Not claimed:** full Zend PHP compatibility (subset compiler only).

---

## Shipped examples (000–009)

Shipped examples under `examples/` are **regression fixtures** for VM/JIT/AOT and the web subset (see `examples/README.md`).

| Example | VM | AOT build | Notes / gates |
|---------|----|-----------|--------------|
| 006-FileUploadWeb | ✅ | ✅ | multipart `$_FILES`; `FILE_UPLOAD_WEB_SMOKE_GATE=1`, `FILE_UPLOAD_WEB_AOT_LINK_GATE=1`, `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` default ([#2009](https://github.com/PurHur/php-compiler/issues/2009), [#2011](https://github.com/PurHur/php-compiler/issues/2011), [#2012](https://github.com/PurHur/php-compiler/issues/2012)) |
| 007-ThrowsWeb | ✅ | 🚧 | throw/catch VM smoke `THROWS_WEB_SMOKE_GATE=1` ([#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2101](https://github.com/PurHur/php-compiler/issues/2101)); AOT link OK, execute segfault under investigation |
| 008-SelfHostProbe | ✅ | ✅ | `SELFHOSTPROBE_AOT_SMOKE_GATE=1` in `examples-aot-smoke.sh` ([#2407](https://github.com/PurHur/php-compiler/issues/2407)) |
| 009-FastCGIWeb | ✅ | ✅ | `FASTCGI_WEB_SMOKE_GATE=1` default ([#2351](https://github.com/PurHur/php-compiler/issues/2351)); FastCGI execute 📋 [#173](https://github.com/PurHur/php-compiler/issues/173); [#2331](https://github.com/PurHur/php-compiler/issues/2331); `FASTCGI_WEB_AOT_SMOKE_GATE=1` |

Example smoke gates in `script/ci-defaults.env`: `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1` (M3 compile-smoke partial probe, [#1937](https://github.com/PurHur/php-compiler/issues/1937)).

See [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md) for bootstrap ladder detail.

## North star ladder

| Milestone | Status |
|-----------|--------|
| **M0** — Small `lib/` bundle runs | ✅ |
| **M1** — Compiler-shaped bundle + compile-smoke | ✅ |
| **M2** — Spine toward full inventory | ✅ **7288** / **7290** (2 deferred: #24115, #27103) |
| **M3** — Native compiles PHP (no Zend emit) | ✅ Smoke + inventory argv driver strict native |
| **M4** — Bootstrap loop (next revision) | ✅ `bootstrap-loop-probe` full ladder |
| **M5** — Full self-host, no `vendor/` cold boot | ✅ Presenter strict + compiled-only empty `build/` cold boot ([#3053](https://github.com/PurHur/php-compiler/issues/3053)) |

**Critical path:** MCJIT execute ([#98](https://github.com/PurHur/php-compiler/issues/98)); honest PHP `main()` in full spine AOT (native bundle-OK probe is bootstrap smoke only).

Contributor detail: [`docs/self-host-target.md`](https://github.com/PurHur/php-compiler/blob/master/docs/self-host-target.md), [`docs/bootstrap-selfhost.md`](https://github.com/PurHur/php-compiler/blob/master/docs/bootstrap-selfhost.md).

---

## What is still missing

**[missing-implementation.html](docs/pages/missing-implementation.html)** — emit spine, LLVM deny list, language subset gaps.

**[capability-comparison.html](docs/pages/capability-comparison.html)** — PHP language/stdlib vs VM / JIT / AOT (from capability matrices).

---

## Contribute

**We do not accept GitHub issues or pull requests without prior coordination.** See [CONTRIBUTING.md](https://github.com/PurHur/php-compiler/blob/master/docs/CONTRIBUTING.md).

When a user-visible gap closes, update **`development-status.md`**, **`index.html`**, and **`missing-implementation.html`**.

[← Back to overview](docs/pages/index.html)
