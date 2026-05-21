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

./phpc lint examples/004-ApiJson/example.php
./phpc run examples/004-ApiJson/example.php
```

AOT (needs LLVM 9 — see `script/install-llvm9.sh` or the `php-compiler:22.04-dev` Docker image):

```console
./phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello
cd examples/001-SimpleWeb && ../../phpc build --project .
# or: ../../phpc build -o .phpc/bin/app example.php
../../phpc serve --aot 127.0.0.1:8080 .
```

Legacy entrypoints still work: `php bin/vm.php`, `php bin/jit.php`, `php bin/compile.php -l`.

## Run matrix

| Example | VM | JIT | AOT build | AOT runtime notes |
|---------|----|-----|-----------|-------------------|
| [000-HelloWorld](000-HelloWorld/) | ✅ `./phpc run` | ✅ `bin/jit.php` | optional | no superglobals |
| [001-SimpleWeb](001-SimpleWeb/) | ✅ `-q` / `-p` / env / `phpc serve` | ✅ `bin/jit.php` | ✅ `phpc build` | runtime `QUERY_STRING` / POST ([#201](https://github.com/PurHur/php-compiler/issues/201), [#257](https://github.com/PurHur/php-compiler/issues/257), [#259](https://github.com/PurHur/php-compiler/issues/259)) |
| [002-StaticWeb](002-StaticWeb/) | ✅ `./phpc run` | ✅ `bin/jit.php` | ✅ recommended | no superglobals — [#247](https://github.com/PurHur/php-compiler/issues/247) execute smoke |
| [004-ApiJson](004-ApiJson/) | ✅ `./phpc run` | ✅ `bin/jit.php` | ✅ `phpc build` | JSON + `http_response_code` — [#270](https://github.com/PurHur/php-compiler/issues/270), [#61](https://github.com/PurHur/php-compiler/issues/61) |

### 000-HelloWorld

Plain `echo`; no CGI superglobals.

```console
./phpc lint examples/000-HelloWorld/example.php
./phpc run examples/000-HelloWorld/example.php
php bin/jit.php examples/000-HelloWorld/example.php
./phpc build -o /tmp/hello examples/000-HelloWorld/example.php && /tmp/hello   # optional
```

### 001-SimpleWeb

Reads `name` from `$_REQUEST` (GET query or POST form body); serves HTML, a POST form, and `/style.css`.

```console
./phpc lint examples/001-SimpleWeb/example.php
./phpc run -q 'name=World' examples/001-SimpleWeb/example.php
./phpc run -p 'name=Posted' examples/001-SimpleWeb/example.php
./phpc serve 127.0.0.1:8080 examples/001-SimpleWeb
curl -s 'http://127.0.0.1:8080/example.php?name=Dev'
curl -s -X POST -d 'name=PostDev' 'http://127.0.0.1:8080/example.php'
cd examples/001-SimpleWeb
../../phpc build -o .phpc/bin/app example.php
../../phpc serve --aot 127.0.0.1:8080 .
```

AOT binaries refresh `$_GET` / `$_POST` / `$_REQUEST` from CGI env on each request unless you bake values at compile time with `-q` on `phpc build`.

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

**001-SimpleWeb**, **002-StaticWeb**, and **004-ApiJson** ship a minimal manifest beside `example.php` ([#274](https://github.com/PurHur/php-compiler/issues/274)):

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

`entry` is the script to compile; `binary` is the default AOT output path for `phpc serve --aot` (see `lib/Web/ProjectManifest.php`).

## CI and local verification

PHPUnit gate: [`test/unit/ExamplesCompileTest.php`](../test/unit/ExamplesCompileTest.php) — every `examples/*/example.php` is linted (`phpc lint`), smoke-run under `bin/vm.php` (GET and POST for **001-SimpleWeb**), and (when LLVM is available) checked with `bin/compile.php -l` / `phpc build` ([#203](https://github.com/PurHur/php-compiler/issues/203), [#243](https://github.com/PurHur/php-compiler/issues/243), [#247](https://github.com/PurHur/php-compiler/issues/247), [#282](https://github.com/PurHur/php-compiler/issues/282), [#259](https://github.com/PurHur/php-compiler/issues/259)).

Before a PR that touches examples or `bin/serve.php`:

```console
make web-smoke              # lint all examples + VM ?name= smoke (001-SimpleWeb)
make examples-web-smoke     # phpc serve + curl GET/POST (001-SimpleWeb, 002-StaticWeb)
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

For **001-SimpleWeb**, `bin/compile.php` is timed **without** compile-time `-q`; the `./compiled` column runs the binary with runtime `QUERY_STRING` (and related CGI env), matching production AOT web binaries.

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.01465 |         0.06149 |         0.11364 |         9.42664 |         0.00163 |
|        001-SimpleWeb |         0.01439 |         0.06331 |         0.10672 |         2.82886 |         0.00165 |
|        002-StaticWeb |         0.01651 |         0.06435 |         0.12040 |         0.62562 |         0.00139 |
|          004-ApiJson |         0.01357 |         0.06180 |         0.11049 |         0.62714 |         0.00118 |
<!-- benchmark table end -->
