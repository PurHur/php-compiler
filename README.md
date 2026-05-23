# A compiler for PHP

[![CircleCI](https://circleci.com/gh/ircmaxell/php-compiler.svg?style=svg)](https://circleci.com/gh/ircmaxell/php-compiler)

**CI:** full gate `./script/ci-local.sh` or `make test`; fast iteration `./script/ci-fast.sh` or `make test-fast` ([#436](https://github.com/PurHur/php-compiler/issues/436)). Docker image: `php-compiler:22.04-dev`. GitHub Actions workflows are disabled (see [#394](https://github.com/PurHur/php-compiler/issues/394)).

Ok, so this used to be a dead project. It required calling out to all sorts of hackery to generate PHP extensions, or PHP itself.

Now, thanks to [FFI landing in PHP 7.4](https://wiki.php.net/rfc/ffi), the potential for all sorts of crazy is HUGE. 

So here we go :)

# Quick start (host PHP)

On a modern Linux host with PHP 8.1+ (8.2 recommended):

```console
git clone https://github.com/PurHur/php-compiler.git
cd php-compiler
composer install
./phpc test --fast             # VM/compliance only (no LLVM compile)
./phpc test                    # full suite (VM, JIT, AOT lint + link)
mkdir my-app && ./phpc init my-app   # phpc.json + public/index.php scaffold
./phpc init --profile miniwebapp my-app   # Router + templates (see examples/003-MiniWebApp/)
./phpc run -r 'echo 1;'        # VM mode (or: php bin/vm.php -r 'echo 1;')
./phpc run -q 'name=Dev' examples/001-SimpleWeb/example.php   # web example without TCP
make web-smoke                 # lint shipped examples + 003-MiniWebApp tree, VM smoke 001-SimpleWeb
make examples-web-smoke        # phpc serve + curl for 001-SimpleWeb, 002-StaticWeb, and 004-ApiJson
make examples-aot-smoke        # phpc build + CLI execute for 000–004 when LLVM ready (#667)
make deploy-smoke              # phpc deploy + PHPC_DEPLOY_ROOT CGI for 001/002 when LLVM ready (#718)
./phpc serve examples/001-SimpleWeb   # http://127.0.0.1:8080/ (or: make serve)
```

The first `ci-local.sh` run downloads a bundled LLVM 9 toolchain into `.llvm/` (see `script/install-llvm9.sh`) and applies vendor patches. No Docker required.

### Quick start (Docker only)

On a host with **only Docker** (no system PHP or LLVM):

```console
git clone https://github.com/PurHur/php-compiler.git
cd php-compiler
make test    # builds php-compiler:22.04-dev if needed, then memory-safe CI in Docker (see script/ci-defaults.env)
```

`make test` is the same CI path as `make test-docker` when the bind-mount works; on harness hosts it falls back to `script/docker-ci-local.sh` (tar copy) like `make test-harness`.

## Self-host bootstrap (experimental)

Compile a **subset** of php-compiler with itself (native AOT), separate from the **web-app** north star ([`examples/003-MiniWebApp`](examples/003-MiniWebApp/) — [#521](https://github.com/PurHur/php-compiler/issues/521), [#539](https://github.com/PurHur/php-compiler/issues/539)). Full ladder, blockers, and Phase A–D gates: [docs/bootstrap-selfhost.md](docs/bootstrap-selfhost.md). Per-file inventory and CFG gaps: [docs/bootstrap-inventory.md](docs/bootstrap-inventory.md) (regenerate with `php script/bootstrap-inventory.php`). ROADMAP Phase 0 stretch: [#78](https://github.com/PurHur/php-compiler/issues/78) ([#540](https://github.com/PurHur/php-compiler/issues/540) namespaced `lib/` link).

**Achieved (minimal subset, 2026-05-23)** — `./script/bootstrap-selfhost-link.sh` builds and runs `test/selfhost/compiler_minimal` (`compiler_minimal bundle OK`); full `lib/` tree native self-host still open ([#212](https://github.com/PurHur/php-compiler/issues/212), [#557](https://github.com/PurHur/php-compiler/issues/557)). Native link sets `PHP_COMPILER_SELFHOST_AOT=1` for LLVM stub gating; `PHP_COMPILER_JIT_PROGRESS_FILE` is progress logging only, not stub control.

**Local** (after `composer install`; Phase C needs LLVM 9 — `./script/install-llvm9.sh` or `.llvm/` from a prior `ci-local.sh` run):

```console
make bootstrap-profile          # Phase A inventory + docs/bootstrap-profile.json + fixture lint
make bootstrap-aot-link         # Phase C: link + run bootstrap fixtures (stdout vs Zend)
./script/bootstrap-selfhost-lint.sh   # bundled lib/Compiler.php AOT lint only
make bootstrap-selfhost-probe   # compile probe (-l / -o build/selfhost)
./script/bootstrap-selfhost-link.sh   # native link + run gate (minimal bundle)
```

**Docker** (LLVM 9 in `php-compiler:22.04-dev`; build once with `make docker-build-22`):

```console
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev bash -lc 'make bootstrap-profile && make bootstrap-aot-link'
```

On harness hosts with an empty bind-mount, use `./script/docker-ci-local.sh` or tar-copy the tree before the commands above (see [Troubleshooting](#troubleshooting)).

| Milestone | Command | Status |
|-----------|---------|--------|
| Phase A inventory | `php script/bootstrap-inventory.php --check` | ✅ vm.php path scan |
| Phase B lib AOT lint | `php bin/compile.php -l lib/*.php` | ✅ **14** top-level `lib/*.php` ([#534](https://github.com/PurHur/php-compiler/pull/534)) |
| Phase B fixture lint | `php script/bootstrap-aot-lint.php` | ✅ **11** procedural targets |
| Phase C native link | `make bootstrap-aot-link` | ✅ **11** link targets ([#538](https://github.com/PurHur/php-compiler/pull/538), [#545](https://github.com/PurHur/php-compiler/pull/545)) |
| Bundled compiler lint | `./script/bootstrap-selfhost-lint.sh` | ✅ `compiler_minimal` closure **lint** ([#559](https://github.com/PurHur/php-compiler/issues/559)) |
| Self-host compile probe | `make bootstrap-selfhost-probe` | ✅ `-l` + `-o build/selfhost` ([#816](https://github.com/PurHur/php-compiler/issues/816), [#913](https://github.com/PurHur/php-compiler/issues/913)) |
| Self-host native link | `./script/bootstrap-selfhost-link.sh` | ✅ `build/selfhost` → `compiler_minimal bundle OK` ([#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913)) |
| Phase D `lib/` link | `make bootstrap-aot-link-lib` | ✅ `lib/OpCode.php` bundle ([#540](https://github.com/PurHur/php-compiler/issues/540)) |

# Installation

## Host requirements

- **PHP 8.1+** (8.2 recommended) with extensions: `tokenizer`, `mbstring`, `dom`, `xml`, `xmlwriter`, `ffi`, `posix`, `phar`
- **Composer**
- **LLVM 9** for JIT/AOT — use the bundled installer (default) or set `PHP_COMPILER_LLVM_PATH` to an existing LLVM 9 tree

On Debian/Ubuntu you can install PHP extensions with:

```console
sudo apt-get install php-cli php-tokenizer php-mbstring php-xml php-ffi php-posix composer
```

Then:

```console
composer install
./script/install-llvm9.sh    # optional if ci-local.sh has not run yet
```

### Environment variables

| Variable | Purpose |
|----------|---------|
| `PHP_COMPILER_PHP` | PHP binary for tests and scripts (default: `php`, or `php8.2` if found) |
| `PHP_COMPILER_EXT_DIR` | Directory containing `.so` extensions (default: `/usr/lib/php/20220829` on PHP 8.2) |
| `PHP_COMPILER_LLVM_PATH` | Path to LLVM 9 `clang`, `ld`, and `libLLVM-9.so.1` (default: repo `.llvm/` after install) |
| `PHP_COMPILER_SKIP_SERVE_TESTS` | Skip `ServeTest` / `ServeAotTest` (use in sandboxes that cannot bind TCP) |
| `PHP_COMPILER_RUN_SERVE_TESTS` | Force HTTP serve integration tests even when loopback bind probe fails |
| `PHP_COMPILER_ALLOW_JIT_SKIP` | Do not fail `ci-local.sh` when LLVM is present but JIT compliance tests are 100% skipped (broken dev env only) |
| `PHP_COMPILER_CI_RAM_GB` | Virtual-memory cap (`ulimit -v`) for the whole CI shell (default `8` GiB; set `0` to disable) |
| `PHP_COMPILER_MEMORY_LIMIT` | PHP `memory_limit` for PHPUnit and spawned `bin/vm.php` children (default `1536M` in CI) |
| `PHP_COMPILER_LLVM_MEMORY_LIMIT` | Higher limit during `@group llvm` phases in `ci-local.sh` (default `4096M`) |
| `PHP_COMPILER_DOCKER_MEM` | Cgroup cap for `script/ci-docker-safe.sh` (default `10g`) |

`script/ci-local.sh` sets LLVM paths automatically when `.llvm/libLLVM-9.so.1` exists. It runs LLVM work in phases (`aot-lint`, `jit`, `aot-link`) so compile subprocesses exit between stages ([#436](https://github.com/PurHur/php-compiler/issues/436)). Use `script/ci-fast.sh` (or `phpc test --fast`) while iterating — same VM/compliance gate without JIT/AOT. It probes `127.0.0.1` bind capability and runs `@group serve` tests when allowed. **Local and Docker CI** (`make test-docker`, `./script/docker-ci-local.sh`) should run those tests — only set `PHP_COMPILER_SKIP_SERVE_TESTS=1` when loopback bind is unavailable.

### Running tests on the host

```console
make test-fast               # VM/compliance only (no LLVM)
make test-local              # same as ./script/ci-local.sh
./script/ci-fast.sh --filter VMTest
./script/ci-local.sh --filter VMTest
make web-smoke
make examples-web-smoke   # HTTP serve + curl (skipped when loopback bind fails)
```

### Developing web apps locally

**AOT deploy quickstart** (build → `phpc deploy` → `PHPC_DEPLOY_ROOT` → nginx CGI sketch): [docs/deploy-web-aot.md](docs/deploy-web-aot.md).

```console
make serve
curl 'http://127.0.0.1:8080/example.php?name=Dev'
```

`bin/serve.php` sets CGI-style superglobals and runs scripts through the VM (same path as `bin/vm.php` with `-q` / `-p`).

To serve a precompiled AOT binary (CGI env per request, static files from docroot):

```console
phpc build -o .phpc/bin/app examples/001-SimpleWeb/example.php
phpc serve --aot 127.0.0.1:8080 examples/001-SimpleWeb
curl 'http://127.0.0.1:8080/example.php?name=Dev'
```

Use `--binary path` or a `phpc.json` `"binary"` field to point at the executable. AOT binaries refresh `$_GET` / `$_SERVER` from `QUERY_STRING` and related env on each run; pass `-q` to `phpc build` to bake superglobals for static pages instead.

Uncaught errors return HTTP 500 with a generic body. Set `PHP_COMPILER_DEBUG=1` to include the exception class, message, and stack trace in the response (details are always logged to stderr).

Non-`.php` files under the docroot (for example `style.css`) are served as static assets with a guessed `Content-Type`; path segments containing `..` are rejected.

## Using docker

Docker is optional on a normal dev machine. On **Runforge / harness hosts** (no system PHP or LLVM), use the PHP 8.2 dev image instead of apt-installing toolchains on the host.

### Container development (PHP 8.2, Ubuntu 22.04)

Build the dev image once (from the repository root; LLVM 9 is baked into `/opt/llvm9`):

```console
make docker-build-22
# equivalent:
docker build -f Docker/dev/ubuntu-22.04/Dockerfile -t php-compiler:22.04-dev .
```

When you bind-mount the repo, a host `.llvm/` directory (if present) overrides the image toolchain; otherwise `PHP_COMPILER_LLVM_PATH` defaults to `/opt/llvm9` and JIT/AOT tests run without re-downloading.

Run the full local CI suite inside the container (same as `./script/ci-local.sh` on the host). This includes HTTP serve integration tests (`ServeTest`, `ServeAotTest`) unless `PHP_COMPILER_SKIP_SERVE_TESTS=1` is set:

```console
make test-docker
# or:
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev ./script/ci-local.sh
```

On **Runforge / harness** hosts (empty bind-mount at `/compiler`), `make test` falls back to copying the repo via tar when the mount has no `vendor/` or `script/ci-local.sh` (same as `make test-harness`):

```console
make test
# equivalent:
make test-harness
./script/docker-ci-local.sh
# optional filter:
make test-harness ARGS='--filter VMTest'
```

When the bind-mount works normally, `make test`, `make test-harness`, and `make test-docker` all run `script/ci-local.sh` in `php-compiler:22.04-dev`. See [docs/local-ci-matrix.md](docs/local-ci-matrix.md) and issue [#245](https://github.com/PurHur/php-compiler/issues/245) for the full local CI matrix.

If the container runs out of memory while running the full suite (process exit code **137**), increase the limit (for example `docker run -m 8g`).

**Do not run several full CI containers at once** — each `vm.php` subprocess can grow large without caps; use `./script/ci-docker-safe.sh` (default `10g` cgroup + `1536M` PHP limit) and keep a single CI run. **`memory_limit=-1` is blocked** in this repo (`script/check-no-unlimited-memory.sh`).

On sandboxes that cannot bind TCP ports, set `PHP_COMPILER_SKIP_SERVE_TESTS=1` before running CI.

Published tag (when available): `ghcr.io/PurHur/php-compiler:dev`. Override with `PHP_COMPILER_DEV_IMAGE`. If `docker pull` fails, build locally with `make docker-build-22` (canonical on harness hosts).

Maintainers can publish after `docker login ghcr.io`:

```console
./script/docker-publish-dev.sh --push
# or: make docker-publish-dev
```

See [docs/local-ci-matrix.md](docs/local-ci-matrix.md#docker-dev-image-issue-202) for the full Docker-only matrix.

Legacy Makefile targets use Ubuntu 16.04 / 18.04 images with PHP 7.4 (`ircmaxell/php-compiler:*` on Docker Hub often 404s; `make test-legacy-16`, `make test-legacy-18`; `make test-18` is a deprecated alias). For day-to-day development, prefer `make test` or host `make test-local` above.

To build legacy images, use make:

```console
me@local:~$ make build
```

This will take a while (upwards of 10 minutes likely). It will install an Ubuntu container with a custom compile of PHP-7.4 and everything you need to get up and running. It will also composer install all dependencies as well as run the pre-processor. Once it's done, you can run legacy tests:

```console
me@local:~$ make test-legacy-16
```

This executes PHPUnit inside the 16.04 container. For current CI, use `make test` (22.04) instead.

To run your own code or play with the compiler, you can open a shell using `make shell`:

```console
me@local:~$ make shell
root@662c59ae4527:/compiler# php bin/jit.php -r 'echo "Hello World\n";'
Hello World
```

# Running Code

There are three main ways of using this compiler:

## VM - Virtual Machine

This compiler mode implements its own PHP Virtual Machine, just like PHP does. This is effectively a giant switch statement in a loop.

No, seriously. It's literally [a giant switch statement](lib/VM.php)...

Practically, it's a REALLY slow way to run your PHP code. Well, it's slow because it's in PHP, and PHP is already running on top of a VM written in C. 

But what if we could change that...

## JIT - Just In Time

This compiler mode takes PHP code and generates machine code out of it. Then, instead of letting the code run in the VM above, it just calls to the machine code.

It's WAY faster to run (faster than PHP 7.4, when you don't account for compile time).

But it also takes a long time to compile (compiling is SLOW, because it's being compiled from PHP).

Every time you run it, it compiles again. 

That brings us to our final mode:

## Compile - Ahead Of Time Compilation

This compiler mode actually generates native machine code, and outputs it to an executable.

This means, that you can take PHP code, and generate a standalone binary. One that's implemented **without a VM**. That means it's (in theory at least) as fast as native C.

Well, that's not true. But it's pretty dang fast.

# Okay, Enough, How can I try?

There are four CLI entrypoints, and all 4 behave (somewhat) like the PHP cli:

 * `php bin/vm.php` - Run code in a VM
 * `php bin/jit.php` - Compile all code, and then run it
 * `php bin/compile.php` - Compile all code, and output a `.o` file.
 * `php bin/print.php` - Compile and output CFG and the generated OpCodes (useful for debugging)

## Executing Code

Specifying code from `STDIN` (this works for all 4 entrypoints):

```console
me@local:~$ echo '<?php echo "Hello World\n";' | php bin/vm.php
Hello World
```

You can also specify on the CLI via `-r` argument:

```console
me@local:~$ php bin/jit.php -r 'echo "Hello World\n";'
Hello World
```

And you can specify a file:

```console
me@local:~$ echo '<?php echo "Hello World\n";' > test.php
me@local:~$ php bin/vm.php test.php
```

When compiling using `bin/compile.php`, you can also specify an "output file" with `-o` (this defaults to the input file, with `.php` removed). This will generate an executable binary on your system, ready to execute

```console
me@local:~$ echo '<?php echo "Hello World\n";' > test.php
me@local:~$ php bin/compile.php -o other test.php
me@local:~$ ./other
Hello World
```

Or, using the default:

```console
me@local:~$ echo '<?php echo "Hello World\n";' > test.php
me@local:~$ php bin/compile.php test.php
me@local:~$ ./test
Hello World
```

## Linting Code

If you pass the `-l` parameter, it will not execute the code, but instead just perform the compilation. This will allow you to test to see if the code even will compile (hint: most currently will not).

## Debugging

Sometimes, you want to see what's going on. If you do, try the `bin/print.php` entrypoint. It will output two types of information. The first is the Control Flow Graph, and the second is the compiled opcodes.

```console
me@local:~$ php bin/print.php -r 'echo "Hello World\n";'

Control Flow Graph:

Block#1
    Terminal_Echo
        expr: LITERAL<inferred:string>('Hello World
        ')
    Terminal_Return


OpCodes:

block_0:
  TYPE_ECHO(0, null, null)
  TYPE_RETURN_VOID(null, null, null)
```

# Roadmap

Development targets a **web-capable PHP subset**: CGI/superglobals, stdlib for small apps, JIT/AOT deployment, and a reference MiniWebApp. See open [GitHub issues](https://github.com/PurHur/php-compiler/issues) for phase labels (`phase-0:Foundation` through `phase-5:reference-app`).

The compiler still supports a limited language subset compared to Zend PHP; many builtins and constructs are VM-only or in progress. See the generated [builtin capability matrix](docs/capabilities.md) and [language construct matrix](docs/capabilities-syntax.md) (classes, methods, visibility, `instanceof`, native user-class link, `match`, arrow functions).

# Troubleshooting

**Empty `/compiler` inside Docker (Runforge / harness)** — `docker run -v "$(pwd):/compiler" …` may show an empty tree even though the repo exists on the host. Symptoms: `make test-docker` fails with missing `vendor/` or `script/ci-local.sh`. Fix: `make test-harness` or `./script/docker-ci-local.sh` (tar-copies the tree into the container). Requires `docker info` and image `php-compiler:22.04-dev` (`make docker-build-22`).

**`libLLVM-9.so.1: cannot open shared object file`** — Run `./script/install-llvm9.sh` or export `LD_LIBRARY_PATH` to include the repo `.llvm/` directory (as `script/ci-local.sh` does).

**Linker / AOT failures** — AOT linking uses `PHP_COMPILER_LLVM_PATH` and bundled `clang-9`/`ld` from `.llvm/` (`lib/AOT/Linker.php`). Ensure `crtbegin.o`, `crtend.o`, and `libgcc.a` exist under `.llvm/gcc/9/`. Re-run `script/install-llvm9.sh` if the bundle is incomplete.

**Missing PHP extensions** — Set `PHP_COMPILER_EXT_DIR` to your PHP's extension directory (`php -i | grep extension_dir`).

**php-parser / lexer errors on PHP 8.2+** — Run `composer install` and `script/apply-patches.sh` so vendored `nikic/php-parser` matches the host PHP version.

# Debugging

Since this is bleeding edge, debuggability is key. To that vein, both `bin/jit.php` and `bin/compile.php` accept a `-y` flag which will output a pair of debugging files (they default to the prefix of the name of the script, but you can specify another prefix following the flag).

```console
me@local:~$ echo '<?php echo "Hello World\n";' > demo.php
me@local:~$ php bin/compile.php -y demo.php
# Produces: 
#   demo - executable of the code
#   demo.bc - LLVM intermediary bytecode associated with the compiled code
#   demo.s - assembly generated by the compiled code

```

Checkout the [examples](examples/) folder.

# Performance

So, is this thing any fast? Well, let's look at the internal benchmarks. You can run them yourself with `make bench`, and it'll give you the following output (running 5 iterations of each test, and averaging the time).

Check out the results in the [Benchmarks](benchmarks/) folder.

This is after the port to using LLVM under the hood. So the port to LLVM appears to have been well worth it, even just from a performance standpoint.

To run the benchmarks yourself, you need to pass a series of ENV vars for each PHP version you want to test. For example, the above chart is generated with::

Without opcache doing optimizations, the `bin/jit.php` is actually able to get close to native PHP with ack(3,9) and mandelbrot (without opcache) for 7.3 and 7.4. It's even able to hang with PHP 8's experimental JIT compiler for ack(3,9). For ack(3,10) it's able to be the fastest execution method.  

Most other tests are actually WAY slower with the `bin/jit.php` compiler. That's because the test itself is slower than the baseline time to parse and compile a file (about 0.2 seconds right now).

And note that this is running the compiler on top of PHP. At some point, the goal is to get the compiler to compile itself, hopefully cutting the time to compile down by at least a few hundred percent.

Simply look at the difference between everything and the "compiled time" column (which is the result of the AOT compiler generating a binary). This shows the potential in this compilation approach. If we can solve the overhead of parsing/compiling in PHP for the `bin/jit.php` examples, then man could this fly...

So yeah, there's definitely potential here... *evil grin*
