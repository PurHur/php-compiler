# Getting started

Guide for **first-time contributors** and **demo presenters**. For the public narrative, see the [status site](https://purhur.github.io/php-compiler/docs/pages/index.html) and [development status](https://purhur.github.io/php-compiler/development-status.html).

## Prerequisites

| Requirement | Notes |
|-------------|--------|
| PHP **8.1+** (8.2 recommended) | Extensions: `tokenizer`, `mbstring`, `dom`, `xml`, `xmlwriter`, `ffi`, `posix`, `phar` |
| **Composer** | `composer install` at repo root |
| **LLVM 9** (for JIT/AOT) | Bundled via `./script/install-llvm9.sh` or first `./script/ci-local.sh` run → `.llvm/` |
| **Patches** | `script/apply-patches.sh` (php-cfg match/nullsafe; Docker CI runs this automatically) |

**Docker-only host:** `make test` builds `php-compiler:22.04-dev` and runs the full suite inside the container.

## Five-minute demo script

Use this order when showing the project to visitors.

### 1. Prove the test harness (fast)

```bash
composer install
./phpc test --fast
```

VM/compliance only — no LLVM link. Expect green after `./script/ci-fast.sh` or `./phpc test --fast` on a healthy tree (remote GHA/Circle are disabled — [#394](https://github.com/PurHur/php-compiler/issues/394)).

### 2. Native binary from PHP

```bash
./phpc build -o /tmp/hello examples/000-HelloWorld/example.php
/tmp/hello
```

**Talking point:** AOT output is a real native executable; deployed apps do not need Zend PHP at runtime.

### 3. Web app in the VM

```bash
./phpc serve examples/003-MiniWebApp
```

Open `http://127.0.0.1:8080/` — home, hello, contact form, JSON API (`/index.php/api/status`). Mention: VM path is production-stable today; native AOT execute for all routes is still partial ([#1044](https://github.com/PurHur/php-compiler/issues/1044)).

### 4. (Optional) Self-host smoke

```bash
script/apply-patches.sh
make bootstrap-selfhost-link
```

Expect stdout: `compiler_minimal bundle OK`. **Talking point:** experimental path toward the compiler compiling its own `lib/` tree ([#1056](https://github.com/PurHur/php-compiler/issues/1056)).

### 5. (Optional) Full local CI

```bash
./script/ci-local.sh
# or: make test-local
```

Needs LLVM + ~8 GiB RAM; includes JIT/AOT lint/link and example smokes.

## `phpc` command cheat sheet

| Command | Purpose |
|---------|---------|
| `./phpc run -r 'echo 1;'` | Run in VM |
| `./phpc serve <docroot>` | HTTP dev server (VM) |
| `./phpc build -o bin/app script.php` | AOT compile to native binary |
| `./phpc build --project .` | Project link from `phpc.json` |
| `./phpc deploy -o dist/` | Package binary + `public/` for CGI deploy |
| `./phpc lint --all path/` | Unsupported-syntax report |
| `./phpc test` / `--fast` | Full / fast local CI |
| `./phpc init --profile miniwebapp dir/` | Scaffold MiniWebApp layout |
| `./phpc doctor` | Environment + optional gate probe |

Legacy entrypoints (`bin/vm.php`, `bin/jit.php`, `bin/compile.php`) still work.

## Documentation guide

| Path | Audience | Content |
|------|----------|---------|
| [README.md](../README.md) | Everyone | Quick start, north stars, CI |
| [docs/pages/](pages/) | **Public** | GitHub Pages — overview + `development-status.md` |
| [docs/deploy-web-aot.md](deploy-web-aot.md) | Operators | AOT deploy + nginx CGI sketch |
| [docs/bootstrap-selfhost.md](bootstrap-selfhost.md) | Contributors | Self-host gates and workflow |
| [docs/miniwebapp-gates.md](miniwebapp-gates.md) | Contributors | Reference app gate ladder |
| [examples/README.md](../examples/README.md) | Everyone | Per-example commands |

**Repo-only (not on GitHub Pages; do not link from public status content):** generated capability matrices (`capabilities.md`, `capabilities-syntax.md`), bootstrap inventory tables (`bootstrap-inventory.md`), CI matrices (`local-ci-matrix.md`), and similar large maps. See [`docs/README.md`](README.md).

## Environment variables (common)

| Variable | Purpose |
|----------|---------|
| `PHP_COMPILER_LLVM_PATH` | LLVM 9 tree (default: repo `.llvm/`) |
| `PHP_COMPILER_PHP` | PHP binary for tests/scripts |
| `PHP_COMPILER_SKIP_SERVE_TESTS` | Skip HTTP tests when loopback bind fails |
| `PHP_COMPILER_DEBUG` | Verbose errors on `phpc serve` / CGI |
| `PHP_COMPILER_SELFHOST_AOT` | Stub-tolerant self-host bundle link |

Full list: [README § Environment variables](../README.md#environment-variables).

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `libLLVM-9.so.1` missing | `./script/install-llvm9.sh` |
| Empty `/compiler` in Docker | `make test-harness` or `./script/docker-ci-local.sh` |
| Parser errors on PHP 8.2+ | `script/apply-patches.sh` |
| Serve tests skipped | Loopback bind; or set `PHP_COMPILER_SKIP_SERVE_TESTS=1` only in sandboxes |

More: [README § Troubleshooting](../README.md#troubleshooting).

## Updating public docs after a milestone

1. Edit [`docs/pages/development-status.md`](pages/development-status.md) (authoritative written status — **no links** to capability/inventory/CI matrices).
2. Adjust [`docs/pages/index.html`](pages/index.html) progress bars / badges if the composite % changed.
3. Sync north-star tables in [README.md](../README.md).

**Contributors only (not part of the public site):** regenerate `capabilities.md` / `capabilities-syntax.md` when builtins change (`php script/capability-matrix.php`, `php script/capability-syntax.php`).

Publish: see [`docs/pages/PAGES.md`](pages/PAGES.md).
