# php-compiler

**Compile PHP to native binaries** — a CFG-based compiler with a bytecode **VM**, **LLVM 9 JIT**, and **AOT** linking. Ship CLI tools and small web apps that run **without Zend PHP at runtime** after `phpc build` or `phpc deploy`.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4)](https://www.php.net/)
[![LLVM](https://img.shields.io/badge/LLVM-9-orange)](https://llvm.org/)
[![Status](https://img.shields.io/badge/docs-status%20site-4F5B93)](https://purhur.github.io/php-compiler/docs/pages/index.html)

> **Stable line (2026)** — First maintained **stable** release of this fork: demo-ready VM + AOT for a **web-capable PHP subset**, reference examples **000–009**, and an experimental **self-host** path (compiler compiling its own `lib/`). Not full Zend PHP compatibility — see [what’s missing](https://purhur.github.io/php-compiler/missing-implementation.html).

---

## Lineage & disclaimer

This repository continues work that began as a **research compiler written in PHP**:

| | |
|---|---|
| **Original project** | [**ircmaxell/php-compiler**](https://github.com/ircmaxell/php-compiler) on GitHub |
| **Original author** | [**Anthony Ferrara**](https://github.com/ircmaxell) (`ircmaxell`) — idea, early architecture, and MIT-licensed codebase (copyright © 2019 Anthony Ferrara; see [LICENSE](LICENSE)) |
| **This fork** | [**PurHur/php-compiler**](https://github.com/PurHur/php-compiler) — LLVM-backed JIT/AOT, `phpc` CLI, web examples, bootstrap/self-host ladder, and ongoing maintenance |

**Disclaimer:** The *concept* of a PHP-in-PHP compiler with VM, JIT, and native compilation comes from Anthony Ferrara’s original project. This fork is a **separate continuation** with substantial new code, tooling, and goals (especially self-host and production-shaped `phpc` workflows). It is **not** an official release from the original author unless stated otherwise. If you cite the idea, please credit the [original repository](https://github.com/ircmaxell/php-compiler) and its author.

---

## What is php-compiler?

Most PHP runs on **Zend** (opcode VM in C). **php-compiler** takes a different path:

1. **Parse** PHP with [php-cfg](https://github.com/ircmaxell/php-cfg) into a control-flow graph (CFG).
2. **Lower** the CFG to internal opcodes (`lib/Compiler.php`).
3. **Execute** via one of three backends:

| Backend | Entry | Role |
|---------|--------|------|
| **VM** | `phpc run`, `bin/vm.php` | Interpreter loop in PHP — correct, flexible, slower |
| **JIT** | `bin/jit.php` | LLVM MCJIT — compile at startup, then run native code |
| **AOT** | `phpc build`, `bin/compile.php` | Link a **standalone executable** — no Zend at runtime |

```mermaid
flowchart LR
  PHP[PHP source] --> CFG[php-cfg CFG]
  CFG --> OPS[Compiler opcodes]
  OPS --> VM[VM interpret]
  OPS --> JIT[LLVM JIT]
  OPS --> AOT[LLVM AOT link]
  AOT --> BIN[Native binary]
```

The project targets a **deliberate subset** of PHP 8.x oriented toward **CLI and CGI-style web apps** (superglobals, routing, templates, sessions, uploads, JSON APIs) — not every language feature or extension Zend provides.

**Active research direction:** [**self-host**](https://github.com/PurHur/php-compiler/issues/1492) — the compiler compiling its own `lib/` tree into native binaries without relying on Zend in the bootstrap loop. Shipped examples under [`examples/`](examples/) (e.g. [MiniWebApp](examples/003-MiniWebApp/)) are **integration test fixtures** for that stack, not a separate product.

---

## Try it in five minutes

**Needs:** PHP **8.1+**, Composer. LLVM **9** only for `build`, JIT, and full CI (not for `phpc test --fast`).

```bash
git clone https://github.com/PurHur/php-compiler.git
cd php-compiler
composer install
./phpc test --fast
```

| Step | Command | What you see |
|------|---------|----------------|
| **Hello, native** | `./phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello` | Standalone executable, no `php` at runtime |
| **Web app (VM)** | `./phpc serve examples/003-MiniWebApp` → open `http://127.0.0.1:8080/` | Router, templates, JSON API |
| **Self-host smoke** | `script/apply-patches.sh && make bootstrap-selfhost-link` | `compiler_minimal bundle OK` (experimental) |

Presenter walkthrough: [`docs/GETTING-STARTED.md`](docs/GETTING-STARTED.md) · public overview: [status site](https://purhur.github.io/php-compiler/docs/pages/index.html).

---

## The `phpc` CLI

[`./phpc`](bin/phpc.php) is the unified developer interface (legacy `bin/vm.php`, `bin/jit.php`, `bin/compile.php` still work).

| Command | Purpose |
|---------|---------|
| `phpc run` | Run a script on the VM (`-q` / `-p` for CGI-style superglobals) |
| `phpc serve` | Dev HTTP server (VM); `phpc serve --aot` serves a prebuilt binary |
| `phpc build` | AOT compile to a native executable; `--project` uses `phpc.json` |
| `phpc deploy` | Package binary + `public/` into a deploy tree |
| `phpc lint` | Report unsupported syntax in a file or tree |
| `phpc test` | Run CI (`--fast` = VM/compliance only, no LLVM) |
| `phpc init` | Scaffold `phpc.json` (`--profile miniwebapp`, `sessionsweb`, `fileupload`) |
| `phpc doctor` | Environment and gate probes |

```bash
./phpc help
./phpc run -r 'echo "Hello\n";'
./phpc build -o .phpc/bin/app examples/001-SimpleWeb/example.php
./phpc serve examples/001-SimpleWeb
```

Manifest format: [`docs/phpc-json.md`](docs/phpc-json.md) · AOT deploy guide: [`docs/deploy-web-aot.md`](docs/deploy-web-aot.md).

---

## Example applications

Reference apps live under [`examples/`](examples/). They prove VM, AOT link, native execute, and deploy paths — see [`examples/README.md`](examples/README.md).

| Example | Highlights |
|---------|------------|
| [000–002](examples/000-HelloWorld/) | CLI hello, simple web, CGI query params |
| [003-MiniWebApp](examples/003-MiniWebApp/) | Router, templates, contact form, JSON API — **AOT execute green** |
| [004-ApiJson](examples/004-ApiJson/) | JSON API |
| [005-SessionsWeb](examples/005-SessionsWeb/) | `session_start`, flash messages |
| [006-FileUploadWeb](examples/006-FileUploadWeb/) | Multipart `$_FILES` |
| [007-ThrowsWeb](examples/007-ThrowsWeb/) | Caught exceptions in forms |
| [008-SelfHostProbe](examples/008-SelfHostProbe/) | Self-host presenter probe |
| [009-FastCGIWeb](examples/009-FastCGIWeb/) | FastCGI-oriented layout |

```bash
make web-smoke              # lint + VM smoke on shipped examples
make examples-aot-smoke     # AOT link + execute (when LLVM is ready)
make examples-web-smoke     # phpc serve + HTTP curls
```

---

## Capabilities & limitations

php-compiler is **not** a drop-in Zend PHP replacement. It implements a **web-capable PHP 8 subset** aimed at small CLI/CGI apps, native deployment, and (experimentally) compiling its own `lib/` tree. Capabilities differ by backend — **VM**, **JIT**, and **AOT** do not always match.

| Column | Meaning |
|--------|---------|
| **VM** | Runs under `phpc run` / `bin/vm.php` — broadest language coverage, slowest |
| **JIT** | `bin/jit.php` — native code via LLVM MCJIT; some CFGs fall back to VM |
| **AOT** | `phpc build` — standalone binary; strictest; many features blocked at link time |

Full matrices (auto-generated): [`docs/capabilities.md`](docs/capabilities.md) (builtins) · [`docs/capabilities-syntax.md`](docs/capabilities-syntax.md) (language) · public [gap tables](https://purhur.github.io/php-compiler/missing-implementation.html) · [PHP vs us](https://purhur.github.io/php-compiler/capability-comparison.html).

### What v1.0 supports well

**Language & OOP (typical app code)**

- Classes, `new`, interfaces, `instanceof`, constructors, visibility, promoted properties, `readonly` classes
- Instance and static methods, `parent::`, late static binding, magic constants (`__DIR__`, `__FILE__`, `__CLASS__`, …)
- Namespaces, `use function` / `use const`, group `use`
- `match`, scalar `declare(strict_types=1)`, union/intersection types (intersection AOT-limited)
- `try` / `catch` / `throw` on VM (compliance-tested); multi-type `catch`
- Attributes — reflection read path (`getAttributes()`, name only; no `newInstance()` on attributes)
- Generators (`yield`, `yield from`) — **VM only**; JIT runs generator scripts on VM fallback
- Enums (backed), traits (simple `use Trait;` — VM-first for some trait paths)

**Web & deployment**

- CGI-style superglobals (`$_GET`, `$_POST`, `$_SERVER`, `$_FILES`, `$_COOKIE`, `$_SESSION`)
- `phpc serve` dev server and `phpc serve --aot` for prebuilt binaries
- Sessions, multipart uploads, JSON APIs — see examples **005–007**
- `phpc build`, `phpc deploy`, `phpc.json` project manifests
- Reference **003-MiniWebApp**: router, templates, forms, native AOT execute on supported routes

**Standard library**

- Large builtin surface for strings, arrays, JSON, hashing, `preg_*`, filesystem, streams — see matrix (~200+ functions with VM/JIT/AOT columns)
- Wave 3 tracked batch: **12/12** language + **13/13** stdlib items closed on master (May 2026)
- Callback builtins (`array_map`, etc.): string/function name callees; **closures in callbacks still deferred**

**Tooling**

- `phpc lint` — scan trees for unsupported syntax before compile
- `phpc doctor` — environment and example gate probes
- Local/Docker CI: `phpc test`, `phpc test --fast`

### Known limitations

**Language (subset gaps)**

| Area | VM | JIT / AOT | Notes |
|------|:--:|:---------:|-------|
| `try` / `catch` / `finally` | Partial | Unwind incomplete | `finally` and post-catch edge cases; JIT EH not production-safe ([#2114](https://github.com/PurHur/php-compiler/issues/2114)) |
| Closures / arrow `fn () =>` | No / limited | Not lowered | Not in v1.0 app path; bootstrap may stub to `null` for self-host link ([#72](https://github.com/PurHur/php-compiler/issues/72), [#142](https://github.com/PurHur/php-compiler/issues/142)) |
| Generators (`yield`) | Yes | No native | JIT/AOT skip or VM-fallback ([#167](https://github.com/PurHur/php-compiler/issues/167)) |
| By-ref parameters | VM | No JIT | [#140](https://github.com/PurHur/php-compiler/issues/140) |
| Enums in AOT | VM/JIT | No | [#1356](https://github.com/PurHur/php-compiler/issues/1356) |
| Full trait adaptation (`insteadof` / `as`) | Partial | Gaps | [#144](https://github.com/PurHur/php-compiler/issues/144) |

**Runtime & platform**

- **Not Zend-compatible** — no `ext-*` ecosystem, no Composer autoload at AOT runtime, no `eval()`, no full reflection beyond supported paths
- **LLVM 9 only** — JIT/AOT tied to bundled toolchain; upgrading LLVM is non-trivial
- **JIT compile cost** — recompiles on each `bin/jit.php` run; not a long-lived FPM replacement
- **Performance** — AOT can be fast; VM path is PHP-on-PHP; see [`benchmarks/`](benchmarks/)
- **Security model** — same trust as running native code; no sandbox; body size limits on `phpc serve` (default 8 MiB)

**Self-host (experimental, not “stable app” scope)**

| Milestone | Status |
|-----------|--------|
| M0–M1 bundled compiler smoke | ✅ |
| M2 spine **726/726** + `bin/vm.php` in link | ✅ |
| M4 gen-2→gen-3 without Zend on compile | ✅ |
| M5 vendor prelink + Zend-free gen-0 seed | 🚧 ([#1492](https://github.com/PurHur/php-compiler/issues/1492)) |
| Full Zend-free cold boot on empty `build/` | 🚧 |

**What we do not target in v1.0**

- Running arbitrary Composer packages unmodified
- WordPress, Laravel, Symfony, or full framework stacks
- pthreads, fibers, or parallel extension semantics
- Every PHP 8.3+ feature as Zend ships it

### Check your code before shipping

```bash
./phpc lint path/to/your-app.php
./phpc lint --project .          # uses phpc.json roots
./phpc build -o /tmp/app entry.php   # fails early on unsupported constructs
```

Regenerate maintainer matrices after builtin changes: `php script/capability-matrix.php` and `php script/capability-syntax.php`.

**Live status:** [Overview](https://purhur.github.io/php-compiler/docs/pages/index.html) · [Development status](https://purhur.github.io/php-compiler/development-status.html).

---

## Installation

### Host (recommended for daily dev)

- **PHP 8.1+** (8.2 recommended): `tokenizer`, `mbstring`, `dom`, `xml`, `xmlwriter`, `ffi`, `posix`, `phar`
- **Composer**
- **LLVM 9** for JIT/AOT — bundled into `.llvm/` by `./script/install-llvm9.sh` or first `./script/ci-local.sh`

```bash
composer install
script/apply-patches.sh    # php-cfg overlays; required before compile
./script/install-llvm9.sh  # optional until you run full CI or phpc build
```

### Docker-only hosts

```bash
make docker-build-22   # once: php-compiler:22.04-dev
make test              # full CI inside container
```

On Runforge/harness sandboxes use `make test-harness` or `./script/docker-ci-local.sh` — see [Troubleshooting](#troubleshooting).

### Environment variables (common)

| Variable | Purpose |
|----------|---------|
| `PHP_COMPILER_PHP` | PHP binary for tests (default `php` or `php8.2`) |
| `PHP_COMPILER_LLVM_PATH` | LLVM 9 tree (default: repo `.llvm/`) |
| `PHP_COMPILER_SKIP_SERVE_TESTS` | Skip HTTP tests when loopback bind fails |
| `PHP_COMPILER_DEBUG` | Verbose errors on `phpc serve` (500 responses) |

Full list: run `./phpc doctor` or see the [local CI matrix](docs/local-ci-matrix.md).

---

## Development & quality gates

Merge quality is enforced **locally or in Docker** (GitHub Actions / CircleCI are disabled on this fork).

| Goal | Command |
|------|---------|
| Fast iteration | `./phpc test --fast` or `./script/ci-fast.sh` |
| Full gate | `./script/ci-local.sh` or `make test` |
| Bootstrap / self-host | `make bootstrap-selfhost-link`, `make bootstrap-wave-check` |

Contributor matrices (regenerate when builtins change):

```bash
php script/capability-matrix.php
php script/capability-syntax.php
```

Deep docs: [`docs/README.md`](docs/README.md) · self-host: [`docs/self-host-target.md`](docs/self-host-target.md) · bootstrap gates: [`docs/bootstrap-selfhost.md`](docs/bootstrap-selfhost.md) · CI matrix: [`docs/local-ci-matrix.md`](docs/local-ci-matrix.md).

---

## Three ways to run code (internals)

### VM — virtual machine

The VM ([`lib/VM.php`](lib/VM.php)) is a classic decode-and-dispatch loop over compiler opcodes. It is the **reference executor** and the fallback when JIT/AOT cannot lower a construct yet. Running PHP on this VM is slower than Zend, but it is how most development and compliance tests run.

### JIT — just-in-time

[`bin/jit.php`](bin/jit.php) lowers opcodes to LLVM IR, uses MCJIT, and jumps into generated machine code. Compile time is significant; some CFG shapes (generators, unstable exception lowering) still fall back to the VM.

### AOT — ahead-of-time

[`bin/compile.php`](bin/compile.php) / `phpc build` emits object code and links a **native executable** with the project runtime ([`lib/AOT/`](lib/AOT/), [`lib/Runtime.php`](lib/Runtime.php)). This is the deployment path for “no PHP installed on the server.”

Debug CFG and opcodes: `php bin/print.php -r 'echo 1;'`

---

## Contributing

**We do not accept drive-by GitHub issues or pull requests** without prior coordination. Contact maintainers on other channels first and align with the AI-agent workflow. Forks are welcome under MIT — see [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Empty repo inside Docker on harness | `make test-harness` or `./script/docker-ci-local.sh` (tar copy) |
| `libLLVM-9.so.1: cannot open` | `./script/install-llvm9.sh` or set `LD_LIBRARY_PATH` to `.llvm/` |
| Parser/lexer errors on PHP 8.2+ | `composer install` + `script/apply-patches.sh` |
| AOT link failures | Re-run `script/install-llvm9.sh`; check `PHP_COMPILER_LLVM_PATH` |

More: [`docs/GETTING-STARTED.md`](docs/GETTING-STARTED.md) · [`docs/local-ci-matrix.md`](docs/local-ci-matrix.md).

---

## License

[MIT](LICENSE) — Copyright © 2019 Anthony Ferrara. This fork is maintained under the same license; see [Lineage & disclaimer](#lineage--disclaimer) for attribution.

---

## Links

| Resource | URL |
|----------|-----|
| **This repository** | https://github.com/PurHur/php-compiler |
| **Original project** | https://github.com/ircmaxell/php-compiler |
| **Status & gaps (GitHub Pages)** | https://purhur.github.io/php-compiler/ |
| **Anthony Ferrara (original author)** | https://github.com/ircmaxell |
| **Benchmarks** | [`benchmarks/`](benchmarks/) (`make bench`) |
