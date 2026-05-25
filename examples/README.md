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

./phpc lint examples/005-SessionsWeb/example.php
./phpc run examples/005-SessionsWeb/example.php
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
| [005-SessionsWeb](005-SessionsWeb/) | ✅ `./phpc run` / `phpc serve` | ✅ `session_start` JIT ([#1882](https://github.com/PurHur/php-compiler/issues/1882)) | 📋 `phpc build` | `$_SESSION` flash across requests — [#1881](https://github.com/PurHur/php-compiler/issues/1881); AOT execute [#1891](https://github.com/PurHur/php-compiler/issues/1891) |
| [003-MiniWebApp](003-MiniWebApp/) | ✅ `phpc serve` | partial | ✅ `phpc build --project` | PATH_INFO — [#489](https://github.com/PurHur/php-compiler/issues/489), runtime [#539](https://github.com/PurHur/php-compiler/issues/539); AOT link ✅ ([#752](https://github.com/PurHur/php-compiler/issues/752)); native execute ✅ ([#764](https://github.com/PurHur/php-compiler/issues/764) closed) |

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

### 003-MiniWebApp

Reference front controller with PATH_INFO routes ([#489](https://github.com/PurHur/php-compiler/issues/489)). VM serve and `examples-web-smoke.sh` curls are green. `phpc build --project` **links** when LLVM is available ([#752](https://github.com/PurHur/php-compiler/issues/752)); native **execute** is green ([#764](https://github.com/PurHur/php-compiler/issues/764) closed — `MiniWebAppAotExecuteTest`, `make miniwebapp-gates`).

```console
./phpc lint --all examples/003-MiniWebApp
./phpc serve 127.0.0.1:8080 examples/003-MiniWebApp
curl -s 'http://127.0.0.1:8080/index.php/hello?name=Dev'
./script/examples-web-smoke.sh
make web-smoke
```

See [003-MiniWebApp/README.md](003-MiniWebApp/README.md) for routes and gate ladder (`make miniwebapp-gates`). AOT deploy quickstart: [docs/deploy-web-aot.md](../docs/deploy-web-aot.md).

### 005-SessionsWeb

`session_start()` plus a POST → redirect → GET flash message ([#1881](https://github.com/PurHur/php-compiler/issues/1881)). VM run shows the empty state; use `phpc serve` and a cookie jar for two-request persistence (see [005-SessionsWeb/README.md](005-SessionsWeb/README.md)).

```console
./phpc lint examples/005-SessionsWeb/example.php
./phpc run examples/005-SessionsWeb/example.php
./phpc serve 127.0.0.1:8080 examples/005-SessionsWeb
jar=/tmp/phpc-sessionsweb.jar
curl -s -c "$jar" 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" -c "$jar" -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" 'http://127.0.0.1:8080/example.php'
```

AOT link/execute: [#1891](https://github.com/PurHur/php-compiler/issues/1891). VM session curls: `SESSIONS_WEB_SMOKE_GATE=1` (default) in `ci-fast.sh` / `ci-local.sh` — `make examples-sessions-smoke` or `examples-web-smoke.sh --sessions-only` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)).

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

Full field reference: [docs/phpc-json.md](../docs/phpc-json.md) ([#727](https://github.com/PurHur/php-compiler/issues/727)).

**001-SimpleWeb**, **002-StaticWeb**, **004-ApiJson**, and **005-SessionsWeb** ship a minimal manifest beside `example.php` ([#274](https://github.com/PurHur/php-compiler/issues/274)):

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
make web-smoke              # lint examples/*/example.php + 003 lint --all + VM ?name= smoke
make examples-web-smoke     # phpc serve + curl GET/POST (001–004 + 005 session flash when SESSIONS_WEB_SMOKE_GATE=1)
make examples-sessions-smoke   # 005-SessionsWeb cookie jar only (#1887)
make examples-aot-smoke     # phpc build + CLI execute (000–004; skips when LLVM missing; 003 execute green #764)
```

Full CI (`./script/ci-local.sh`) runs `examples-aot-smoke.sh` after PHPUnit `@group aot-link` when LLVM is available (`EXAMPLES_AOT_SMOKE_GATE=1` default in `script/ci-defaults.env`; set `EXAMPLES_AOT_SMOKE_GATE=0` to skip during iteration — [#674](https://github.com/PurHur/php-compiler/issues/674)). Not run in `ci-fast.sh`.

Full suite on the host (after `composer install`):

```console
./script/ci-local.sh
EXAMPLES_AOT_SMOKE_GATE=0 ./script/ci-local.sh   # skip 000–004 CLI AOT execute smoke (#674)
```

In Docker (preferred on harness hosts without host PHP/LLVM):

```console
make test-docker            # or: ./script/docker-ci-local.sh
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev ./script/ci-local.sh
```

Root README quick start and local CI matrix: [#48](https://github.com/PurHur/php-compiler/issues/48), [#245](https://github.com/PurHur/php-compiler/issues/245).

## Benchmark results

Each example includes a benchmark that compares VM, JIT, and (when LLVM is present) AOT against native `php`. Regenerate this table with `script/rebuild-examples.php` ([#60](https://github.com/PurHur/php-compiler/issues/60)).

```console
MINIWEBAPP_LINT_GATE=1 ./script/rebuild-examples.php
# or: BENCH_MINIWEBAPP=1 ./script/rebuild-examples.php
```

For **001-SimpleWeb**, `bin/compile.php` is timed **without** compile-time `-q`; the `./compiled` column runs the binary with runtime `QUERY_STRING` (and related CGI env), matching production AOT web binaries.

For **003-MiniWebApp**, VM/JIT/native columns run `public/index.php` with `PATH_INFO=/home` (and related CGI env) from the example `public/` directory ([#491](https://github.com/PurHur/php-compiler/issues/491), runtime [#539](https://github.com/PurHur/php-compiler/issues/539)). AOT columns time `phpc build --project` and `.phpc/bin/app` with the same CGI overlay when LLVM is ready and execute returns HTML ([#716](https://github.com/PurHur/php-compiler/issues/716); execute [#764](https://github.com/PurHur/php-compiler/issues/764) closed). The row is omitted when `phpc lint --all examples/003-MiniWebApp` fails unless `BENCH_MINIWEBAPP=1`.

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.01013 |         0.04243 |         0.17881 |         0.73837 |         0.00114 |
|        001-SimpleWeb |         0.00853 |         0.04411 |         0.18525 |         0.72047 |         0.00124 |
|        002-StaticWeb |         0.00879 |         0.04392 |         0.17811 |         0.71660 |         0.00105 |
|       003-MiniWebApp |         0.00867 |         0.09493 |         0.55299 |         1.20217 |         0.00101 |
|          004-ApiJson |         0.00824 |         0.04377 |         0.18179 |         0.73902 |         0.00112 |
<!-- benchmark table end -->
