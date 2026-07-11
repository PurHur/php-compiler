# Bootstrap SDK platform contract

**Audience:** Compiler / self-host contributors using the Bootstrap SDK (`prelinked/bootstrap-gen0/`, `build/bin-compile-aot-inventory`, spine probes).  
**Living tracker:** [#15606](https://github.com/PurHur/php-compiler/issues/15606) · **Machine-readable SSOT:** [bootstrap-sdk-platform.json](bootstrap-sdk-platform.json) · **Workflow:** [bootstrap-dev-workflow.md](bootstrap-dev-workflow.md) · **Onboarding:** [GETTING-STARTED.md](GETTING-STARTED.md) § Bootstrap contributors

This document is the **supported platform contract** for Bootstrap SDK development and CI gates. The User SDK (`phpc run` / `phpc build` for shipped examples) has a wider host matrix for VM-only work; bootstrap prelinks and spine gates do not.

Drift guard: `php script/check-bootstrap-sdk-platform.php` (also in `./script/check-generated-docs.sh`).

---

## Supported (contract)

| Requirement | Contract |
|-------------|----------|
| **OS** | **Linux** (glibc; Ubuntu 22.04 is the reference image) |
| **CPU** | **x86_64** (amd64) |
| **LLVM** | **9** only — bundled `.llvm/` via `./script/install-llvm9.sh` or `/opt/llvm9` in `php-compiler:22.04-dev` |
| **RAM (host CI)** | **8 GiB** floor (`PHP_COMPILER_CI_RAM_GB` in `script/ci-defaults.env`) |
| **RAM (Docker)** | **10 GiB** recommended for spine link (`PHP_COMPILER_DOCKER_MEM=10g`) |
| **Docker** | **Recommended** — `make docker-build-22` → `php-compiler:22.04-dev`; use `./script/docker-exec.sh` on harness hosts |
| **Disk** | ~2 GiB for LLVM tree + `build/` artifacts during spine link |
| **Prelinked blobs** | Committed `prelinked/bootstrap-gen0/` are built and tested on **Linux x86_64 + LLVM 9** |

Cold start entry: `./phpc bootstrap init` (see [bootstrap-dev-workflow.md § First-time setup](bootstrap-dev-workflow.md#first-time-setup)).

---

## Harness / Docker (required on sandboxes)

| Rule | Detail |
|------|--------|
| **Use wrappers** | `./script/docker-exec.sh`, `make test-harness`, `./script/docker-ci-local.sh` — not raw `docker run -v "$(pwd):/compiler"` ([#245](https://github.com/PurHur/php-compiler/issues/245)) |
| **Resource limits** | Harness may set `HARNESS_DOCKER_RUN_OPTS` (e.g. `--memory=8g`); override with `PHP_COMPILER_DOCKER_RUN_OPTS` when needed |
| **OOM during spine link** | Raise `PHP_COMPILER_DOCKER_MEM`; avoid parallel `docker-exec` on low-RAM hosts |

Full matrix: [local-ci-matrix.md](local-ci-matrix.md).

---

## Tier 0 harness (Zend — optional but common)

Bootstrap **Tier 1** compile does not require Composer. Tier 0 (PHPUnit, `ci-fast.sh`, doc-sync gates) does:

| Requirement | Notes |
|-------------|--------|
| **PHP** | **8.1+** (8.2 matches locked `composer.json`) |
| **Extensions** | `tokenizer`, `mbstring`, `dom`, `xml`, `xmlwriter`, `ffi`, `posix`, `phar` |
| **Composer** | `composer install --ignore-platform-reqs` when host PHP is 8.3+ |
| **Patches** | `script/apply-patches.sh` before compile-sensitive work |

Enable with `./phpc bootstrap init --with-composer`.

---

## Explicit non-goals (not supported for Bootstrap SDK)

| Platform / setup | Status |
|------------------|--------|
| **macOS** (Intel or Apple Silicon) | **Not supported** for bootstrap prelinks, spine link, or `bootstrap-loop-probe` |
| **Linux aarch64 / ARM64** | **Not supported** — no committed prelinked gen-0 for ARM |
| **Windows** (native or WSL without Docker) | **Not supported** for bootstrap gates |
| **LLVM 14+** as default bootstrap toolchain | **Not supported** until [#174](https://github.com/PurHur/php-compiler/issues/174) FFI lands; CI still pins LLVM 9 |
| **Zend-free full dev** (no Composer / no PHPUnit) | **Tier 1 compile** via `./phpc bootstrap init` ([#15600](https://github.com/PurHur/php-compiler/issues/15600)); full PHPUnit harness still Tier 0 — [#15599](https://github.com/PurHur/php-compiler/issues/15599) |
| **Honest full-spine compile without gen-0 sidecar** | **Not required for daily dev** — release criterion [#15597](https://github.com/PurHur/php-compiler/issues/15597) |

Contributors on unsupported hosts should use **Docker** (`php-compiler:22.04-dev`) for all Tier 1–2 bootstrap work. VM-only User SDK iteration may work on other OSes; do not expect bootstrap spine or prelink gates to pass there.

---

## Verification commands

```bash
./phpc doctor --selfhost          # gate ladder + onboarding pointers
./phpc bootstrap init             # Tier 1 cold start
make north-star5-verify-fast      # daily bootstrap verify (~1–2 min)
php script/bootstrap-spine-count.php
```

---

## Related docs

| Doc | Content |
|-----|---------|
| [bootstrap-dev-workflow.md](bootstrap-dev-workflow.md) | Tiered gen-1+ daily path |
| [bootstrap-generations.md](bootstrap-generations.md) | Gen-0…gen-3 artifacts |
| [bootstrap-selfhost.md](bootstrap-selfhost.md) | Full gate table |
| [local-ci-matrix.md](local-ci-matrix.md) | Env vars, Docker harness |
| [GETTING-STARTED.md](GETTING-STARTED.md) | Bootstrap contributors § (default onboarding) |
