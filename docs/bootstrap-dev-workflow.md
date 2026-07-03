# Bootstrap development workflow (gen-1+ daily path)

**Audience:** Compiler contributors working on self-host / bootstrap / spine inventory.  
**Living tracker:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) · **Generation ladder:** [bootstrap-generations.md](bootstrap-generations.md) · **Gate table:** [bootstrap-selfhost.md](bootstrap-selfhost.md)

This document defines the **tiered workflow** for day-to-day development: Zend stays on the **test harness**; the **native gen-2 driver** owns compile/bootstrap iteration.

---

## Two release channels (do not conflate)

| Channel | Product | Primary gate | Who |
|---------|---------|--------------|-----|
| **User SDK** | `phpc run` / `phpc build` for examples 000–009 | `script/release-readiness.sh` | App authors on the PHP subset |
| **Bootstrap SDK** | `build/bin-compile-aot-inventory` + `prelinked/bootstrap-gen0/` | `make north-star5-verify-fast` + spine sidecar sync | Compiler / self-host contributors |

Ship **Bootstrap SDK v1.x** only when `BOOTSTRAP_M5_NO_ZEND=1 make bootstrap-loop-probe` is green **without** relying on stale sidecar recovery. Until then, treat gen-1+ as the **compile loop** and Zend as the **harness**.

---

## Three tiers

```text
Tier 0 — Harness (Zend)     composer, apply-patches, phpunit, ci-fast
Tier 1 — Daily compile      build/bin-compile-aot-inventory (gen-2 argv driver)
Tier 2 — Bootstrap verify   north-star5-verify-fast, bootstrap-loop-probe
```

| Tier | When | Tools |
|------|------|-------|
| **0** | Once per clone; every PHPUnit / doc-sync PR | `composer install`, `script/apply-patches.sh`, `./script/ci-fast.sh` |
| **1** | Compiling fixtures, examples, local AOT during feature work | `./build/bin-compile-aot-inventory -o OUT SOURCE.php` |
| **2** | Before merge; after spine or gen-0 edits | `make north-star5-verify-fast`, `make bootstrap-loop-probe` |

**Rule:** Do not aim for “Zend-free development” yet. Aim for **Zend-free compile** on the paths you are bootstrapping.

---

## Prerequisites

| Requirement | Notes |
|-------------|--------|
| **Docker** (recommended) | `make docker-build-22` → `php-compiler:22.04-dev` (LLVM 9 at `/opt/llvm9`) |
| **Host PHP 8.1+** | For Tier 0 only; 8.2 matches locked `composer.json` |
| **LLVM 9** | Required for Tier 1–2; host: `./script/install-llvm9.sh` → `.llvm/` |
| **RAM** | 8 GiB CI floor; spine link benefits from 10 GiB Docker (`PHP_COMPILER_DOCKER_MEM`) |
| **Platform** | **Linux x86_64** + LLVM 9 — see [bootstrap-sdk-platform.md](bootstrap-sdk-platform.md) ([#15606](https://github.com/PurHur/php-compiler/issues/15606)) |

Harness hosts: use `./script/docker-exec.sh` — never raw `docker run -v "$(pwd):/compiler"` on Runforge ([#245](https://github.com/PurHur/php-compiler/issues/245)).

---

## First-time setup

```bash
git clone https://github.com/PurHur/php-compiler.git && cd php-compiler
make docker-build-22   # once

# Tier 1 only (no composer):
./phpc bootstrap init --skip-verify
# or with Tier 0 harness:
./phpc bootstrap init --with-composer

./script/docker-exec.sh -- bash -lc '
  composer install --ignore-platform-reqs -q
  script/apply-patches.sh
  make bootstrap-selfhost-link
  ./build/selfhost    # → compiler_minimal bundle OK
'
```

Verify bootstrap health:

```bash
./script/docker-exec.sh -- bash -lc './script/north-star5-verify.sh --fast'
./phpc doctor --selfhost
```

---

## Daily loop (Tier 1 — gen-2 driver)

After Tier 0 setup, use the **inventory argv driver** for compile work (gen-2 in the M4 ladder):

```bash
./script/docker-exec.sh -- bash -lc '
  source script/php-env.sh
  # Ensure gen-0 / gen-2 driver exists
  test -x build/bin-compile-aot-inventory || make bootstrap-selfhost-ensure-gen0-driver

  ./build/bin-compile-aot-inventory -o /tmp/compiler-smoke \
    test/bootstrap-aot/compiler_smoke.php
  /tmp/compiler-smoke    # → compiler smoke
'
```

| Artifact | Role |
|----------|------|
| `prelinked/bootstrap-gen0/bin-compile-aot` | Committed gen-0 seed (no Zend when `build/` empty) |
| `build/bin-compile-aot-inventory` | **Preferred** argv driver for spine / full revision |
| `build/bin-compile-aot` | Legacy gen-0 alias; spine probes may refresh this |

**Preferred argv shape** ([#2866](https://github.com/PurHur/php-compiler/issues/2866)):

```bash
./build/bin-compile-aot-inventory -o build/out test/selfhost/compiler_lib_spine_smoke/main.php
```

**Still use Zend for:**

- `php bin/compile.php -l …` (fast lint during editing)
- `vendor/bin/phpunit` (full test matrix)
- `composer install` / `script/apply-patches.sh`

### Gen-N compiles changed sources ([#15598](https://github.com/PurHur/php-compiler/issues/15598))

After editing `lib/` or spine entry files, gen-2 must compile the **working tree** — gen-3 must reflect the edit, not copy stale `prelinked/bootstrap-gen0/` bytes ([#8710](https://github.com/PurHur/php-compiler/issues/8710)).

```bash
# 1. Edit lib/ or test/selfhost/compiler_lib_spine_smoke/main.php
vim lib/Compiler.php

# 2. Recompile with gen-2 inventory argv driver
./build/bin-compile-aot-inventory -o build/my-gen3 \
  test/selfhost/compiler_lib_spine_smoke/main.php

# 3. Run gen-3 smoke — output must match your edit
./build/my-gen3

# Guard: probe fails when gen-3 byte-matches stale prelinked gen-0
make bootstrap-changed-sources-probe
# or: ./script/bootstrap-changed-sources-probe.sh
```

The probe temporarily patches `examples/000-HelloWorld/example.php` and appends a comment to `lib/OpCode.php`, recompiles via gen-2 (Zend fallback when native sidecar masks edits), and asserts gen-3 output hash and runtime marker both change. Full `lib/Compiler.php` / spine edits follow the same workflow above. Set `BOOTSTRAP_ALLOW_STALE_SIDECAR=1` only for intentional stamp-only PRs.

---

## Before merge (Tier 2)

### Every bootstrap-touching PR

```bash
make north-star5-verify-fast          # ~1–2 min
# or in Docker:
./script/docker-exec.sh -- bash -lc 'make north-star5-verify-fast'
```

### After bootstrap / gen-0 / vendor prelink work

```bash
./script/north-star5-verify.sh --strict   # ~1h — pre-merge only
```

### Full generation ladder (weekly or before spine release)

```bash
./script/docker-exec.sh -- bash -lc './script/bootstrap-loop-probe.sh'   # ~9 min
```

---

## Spine entry edits — mandatory checklist

**SSOT:** `test/selfhost/compiler_lib_spine_smoke/main.php`  
**Counts:** `php script/bootstrap-spine-count.php` (must match `docs/bootstrap-inventory.md` Phase A total)

When inventory grows (new `lib/` / `ext/` on the `bin/vm.php` path):

1. **Discover gaps**

   ```bash
   php script/check-selfhost-spine-coverage-sync.php
   # or auto-suggest next includes:
   php script/bootstrap-selfhost-next-includes.php \
     --bundle=test/selfhost/compiler_lib_spine_smoke/main.php --limit=25
   ```

2. **Add `require_once` lines** to `compiler_lib_spine_smoke/main.php` (no duplicates — breaks `N/N` ratio).

3. **Refresh doc footnotes** — spine count must appear in README, `development-status.md`, etc.:

   ```bash
   php script/check-selfhost-spine-count-sync.php
   make bootstrap-profile   # regenerates inventory + profile when needed
   ```

4. **Refresh gen-0 sidecars** (required before merge):

   ```bash
   make bootstrap-gen0-refresh-sidecar
   # = BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1 spine link
   #   + copy build/.m3_* → prelinked/bootstrap-gen0/
   #   + manifest refresh + check-bootstrap-gen0-manifest-sync.php
   ```

5. **Verify**

   ```bash
   php script/check-selfhost-spine-sidecar-sync.php
   make north-star5-verify-fast
   ./script/bootstrap-loop-probe.sh
   ```

6. **Commit together** in one PR (or stamp-only follow-up with `BOOTSTRAP_ALLOW_STALE_SIDECAR=1` only when intentional):

   - `test/selfhost/compiler_lib_spine_smoke/main.php`
   - `prelinked/bootstrap-gen0/` (`.m3_compiler_lib_sidecar.sha`, blobs, `manifest.json`)
   - Doc footnotes / `BootstrapSelfhostBundleTest.php` spine `assertSame`

GitHub Actions runs the same spine gates on matching paths — see [`.github/workflows/bootstrap-spine-gate.yml`](../.github/workflows/bootstrap-spine-gate.yml).

---

## When to use Zend vs gen-2 vs Docker

| Task | Use |
|------|-----|
| Run PHPUnit / compliance | **Zend** — `make test-fast` / `ci-fast.sh` |
| Lint single file quickly | **Zend** — `php bin/compile.php -l path.php` |
| Compile bootstrap fixture / spine | **gen-2** — `build/bin-compile-aot-inventory -o OUT SRC` |
| Cold empty `build/` | **gen-0 prelinked** — `BOOTSTRAP_M5_NO_ZEND=1 make bootstrap-selfhost-link` |
| LLVM / AOT / spine link | **Docker** — `./script/docker-exec.sh` |
| Fast VM spine smoke | **gen-2 binary** — `make bootstrap-selfhost-vm-driver-execute-probe` (~20 ms) |

---

## Common failures

| Symptom | Cause | Fix |
|---------|-------|-----|
| `check-selfhost-spine-coverage-sync: FAILED` | New inventory files not in spine | `bootstrap-selfhost-next-includes.php` + add `require_once` |
| `check-selfhost-spine-sidecar-sync: FAILED` | Spine edited; stamp stale | `make bootstrap-gen0-refresh-sidecar` |
| `bootstrap-loop-probe: gen-2→gen-3 … exit 134` | Stale sidecar after spine PR | Run step 4 above, retry probe |
| `parseAndCompile returned null (parser/CFG spine)` | Native full-spine parse not complete; sidecar recovery | Expected today; track honest compile % ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| `north-star5-verify-fast` step 2 fail | Spine/doc count drift | `check-selfhost-spine-count-sync.php` |
| `BOOTSTRAP_M5_NO_ZEND=1` hard fail | No prelinked gen-0 or sidecar fallback refused | Seed `prelinked/bootstrap-gen0/`; do not use on partial setups |
| Docker lock / OOM | Parallel `docker-exec` or low RAM | `rm -f .php-compiler-ci.lock`; set `PHP_COMPILER_DOCKER_MEM=10g` |

---

## Honest compile metric (release criterion)

A **Bootstrap SDK** release should report:

- Spine coverage: `N/N` from `bootstrap-spine-count.php`
- Sidecar stamp: `check-selfhost-spine-sidecar-sync.php` OK
- Loop: `bootstrap-loop-probe` exit 0
- **Sidecar-free full-spine compile:** gen-2 argv compile of `compiler_lib_spine_smoke/main.php` succeeds **without** `recovered via gen-0 sidecar` in stderr *(not yet required for daily dev)*

Track regression when stderr contains `native parse spine null — recovered via gen-0 sidecar`.

---

## CI / release pipelines

| Pipeline | Entry | When |
|----------|-------|------|
| Fast PR | `.github/workflows/bootstrap-spine-gate.yml` | Spine / gen-0 / inventory path changes |
| Full loop (manual) | Same workflow `workflow_dispatch` job `bootstrap-loop` | Pre-release / weekly |
| User release | `script/release-readiness.sh [--full] --json` | v1.x app SDK review |
| Local full | `./script/ci-local.sh` | Pre-merge compiler changes |

Remote GHA was paused ([#394](https://github.com/PurHur/php-compiler/issues/394)); `bootstrap-spine-gate.yml` is the **minimal re-enabled** workflow for bootstrap contributors. Other workflows remain under [`.github/workflows-disabled/`](../.github/workflows-disabled/).

---

## Gen-1+ release epic (GitHub issues)

Track progress toward **gen-1+ only** development ([#1492](https://github.com/PurHur/php-compiler/issues/1492)):

| # | Issue | Theme |
|---|-------|--------|
| 1 | [#15597](https://github.com/PurHur/php-compiler/issues/15597) | Honest full-spine native compile (no sidecar fallback) |
| 2 | [#15598](https://github.com/PurHur/php-compiler/issues/15598) | Gen-N compiles **changed** sources — **landed:** `make bootstrap-changed-sources-probe` (also `bootstrap-changed-tree-probe` fixture scaffold) |
| 3 | [#15599](https://github.com/PurHur/php-compiler/issues/15599) | Native test harness (no Zend PHPUnit) |
| 4 | [#15600](https://github.com/PurHur/php-compiler/issues/15600) | Bootstrap cold path without `composer install` |
| 5 | [#15601](https://github.com/PurHur/php-compiler/issues/15601) | Native lint via gen-2 driver |
| 6 | [#15602](https://github.com/PurHur/php-compiler/issues/15602) | Bootstrap SDK release tarball |
| 7 | [#15603](https://github.com/PurHur/php-compiler/issues/15603) | Honest compile CI gate — **landed:** `BOOTSTRAP_HONEST_COMPILE_GATE=1`, `bootstrap-loop-probe --honest-compile` |
| 8 | [#15604](https://github.com/PurHur/php-compiler/issues/15604) | Inventory argv driver without emit-helper sidecar |
| 9 | [#15605](https://github.com/PurHur/php-compiler/issues/15605) | Flip default onboarding to gen-1+ tiers |
| 10 | [#15606](https://github.com/PurHur/php-compiler/issues/15606) | Bootstrap SDK platform contract |

### Shipped in this epic (starter)

```bash
# Tier 1 cold start without composer ( #15600 )
phpc bootstrap init
make bootstrap-init

# Native lint via gen-2 driver -l (#15601)
phpc lint --native test/bootstrap-aot/compiler_smoke.php
./script/bootstrap-native-lint.sh path/to/file.php
# Fallback when build/bin-compile-aot-inventory is missing: php bin/compile.php -l

# Bootstrap SDK release tarball (#15602)
make bootstrap-sdk-pack
# → build/php-compiler-bootstrap-{spine-sha-prefix}.tar.gz
# Extract at repo root to seed prelinked/bootstrap-gen0/ + vendor *.o

# Honest compile gate — opt-in; fails until #15597 closes (#15603)
BOOTSTRAP_HONEST_COMPILE_GATE=1 ./script/bootstrap-loop-probe.sh
./script/bootstrap-loop-probe.sh --honest-compile

# Changed-sources guard — gen-2 must emit working-tree edits (#15598)
make bootstrap-changed-sources-probe
# Fixture-only scaffold (legacy): make bootstrap-changed-tree-probe
```

---

## Related docs

| Doc | Content |
|-----|---------|
| [bootstrap-sdk-platform.md](bootstrap-sdk-platform.md) | Linux x86_64 / LLVM 9 platform contract ([#15606](https://github.com/PurHur/php-compiler/issues/15606)) |
| [bootstrap-generations.md](bootstrap-generations.md) | Gen-0…gen-3 artifacts |
| [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) | M3 allowlist, ~20 ms probes |
| [bootstrap-selfhost.md](bootstrap-selfhost.md) | Full gate table |
| [self-host-target.md](self-host-target.md) | M5 definition of done |
| [local-ci-matrix.md](local-ci-matrix.md) | Env vars, Docker harness |
| [GETTING-STARTED.md](GETTING-STARTED.md) §6–7b | Demo + north-star presenters |
