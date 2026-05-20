# Examples

Shipped demos live under `examples/00x-*/` with an `example.php` entry script. Use the unified **`phpc`** CLI from the repo root (wrapper around `bin/vm.php`, `bin/compile.php`, `bin/lint.php`, and `bin/serve.php`).

## Quick start (all examples)

```console
./phpc lint examples/000-HelloWorld/example.php
./phpc run examples/000-HelloWorld/example.php

./phpc lint examples/001-SimpleWeb/example.php
./phpc run -q 'name=World' examples/001-SimpleWeb/example.php
./phpc serve 127.0.0.1:8080 examples/001-SimpleWeb

./phpc lint examples/002-StaticWeb/example.php
./phpc run examples/002-StaticWeb/example.php
```

AOT (needs LLVM 9 — see `script/install-llvm9.sh` or the `php-compiler:22.04-dev` Docker image):

```console
./phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello
cd examples/001-SimpleWeb && ../../phpc build -o .phpc/bin/app example.php
../../phpc serve --aot 127.0.0.1:8080 .
```

Legacy entrypoints still work: `php bin/vm.php`, `php bin/jit.php`, `php bin/compile.php -l`.

## Run matrix

| Example | VM | JIT | AOT build | AOT runtime notes |
|---------|----|-----|-----------|-------------------|
| [000-HelloWorld](000-HelloWorld/) | ✅ `./phpc run` | ✅ `bin/jit.php` | optional | no superglobals |
| [001-SimpleWeb](001-SimpleWeb/) | ✅ `-q` / env / `phpc serve` | ✅ `bin/jit.php` | ✅ `phpc build` | runtime `QUERY_STRING` / POST ([#201](https://github.com/PurHur/php-compiler/issues/201), [#257](https://github.com/PurHur/php-compiler/issues/257)) |
| [002-StaticWeb](002-StaticWeb/) | ✅ `./phpc run` | ✅ `bin/jit.php` | ✅ recommended | no superglobals — [#247](https://github.com/PurHur/php-compiler/issues/247) execute smoke |

### 000-HelloWorld

Plain `echo`; no CGI superglobals.

```console
./phpc lint examples/000-HelloWorld/example.php
./phpc run examples/000-HelloWorld/example.php
php bin/jit.php examples/000-HelloWorld/example.php
./phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello   # optional
```

### 001-SimpleWeb

Reads `?name=` from `$_GET` / `$_REQUEST` or POST body; serves HTML and `/style.css`.

```console
./phpc lint examples/001-SimpleWeb/example.php
./phpc run -q 'name=World' examples/001-SimpleWeb/example.php
./phpc run -p 'name=Posted' examples/001-SimpleWeb/example.php
./phpc serve 127.0.0.1:8080 examples/001-SimpleWeb
cd examples/001-SimpleWeb
../../phpc build -o .phpc/bin/app example.php
../../phpc serve --aot 127.0.0.1:8080 .
```

AOT binaries refresh `$_GET` / `$_SERVER` from `QUERY_STRING` (and related env) on each request unless you bake values at compile time with `-q` on `phpc build`.

### 002-StaticWeb

Static page (no superglobals); good default for first AOT compile.

```console
./phpc lint examples/002-StaticWeb/example.php
./phpc run examples/002-StaticWeb/example.php
php bin/jit.php examples/002-StaticWeb/example.php
cd examples/002-StaticWeb
../../phpc build -o .phpc/bin/app example.php && ./.phpc/bin/app
../../phpc serve --aot 127.0.0.1:8080 .
```

## `phpc.json` (web examples)

**001-SimpleWeb** and **002-StaticWeb** ship a minimal manifest beside `example.php` ([#274](https://github.com/PurHur/php-compiler/issues/274)):

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

`entry` is the script to compile; `binary` is the default AOT output path for `phpc serve --aot` (see `lib/Web/ProjectManifest.php`).

## CI and local verification

PHPUnit gate: [`test/unit/ExamplesCompileTest.php`](../test/unit/ExamplesCompileTest.php) — every `examples/*/example.php` is linted (`phpc lint`), smoke-run under `bin/vm.php`, and (when LLVM is available) checked with `bin/compile.php -l` / `phpc build` ([#203](https://github.com/PurHur/php-compiler/issues/203), [#243](https://github.com/PurHur/php-compiler/issues/243), [#247](https://github.com/PurHur/php-compiler/issues/247), [#282](https://github.com/PurHur/php-compiler/issues/282)).

Before a PR that touches examples or `bin/serve.php`:

```console
make web-smoke              # lint all examples + VM ?name= smoke (001-SimpleWeb)
make examples-web-smoke     # phpc serve + curl (001-SimpleWeb, 002-StaticWeb)
```

Full suite on the host (after `composer install`):

```console
./script/ci-local.sh
```

In Docker (preferred on harness hosts without host PHP/LLVM):

```console
make test-docker            # or: ./script/docker-ci-local.sh
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev ./script/ci-local.sh
```

Root README quick start and local CI matrix: [#48](https://github.com/PurHur/php-compiler/issues/48), [#245](https://github.com/PurHur/php-compiler/issues/245).

## Benchmark results

Each example includes a benchmark that compares VM, JIT, and (when LLVM is present) AOT against native `php`. Regenerate this table with `script/rebuild-examples.php` ([#60](https://github.com/PurHur/php-compiler/issues/60)).

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.00695 |         0.03487 |         0.05455 |             n/a |             n/a |
|        001-SimpleWeb |         0.00714 |         0.03649 |         0.05764 |             n/a |             n/a |
|        002-StaticWeb |         0.00713 |         0.03538 |         0.05463 |             n/a |             n/a |
<!-- benchmark table end -->
