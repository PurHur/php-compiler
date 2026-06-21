# Examples

Shipped demos live under `examples/00x-*/` with an `example.php` entry script. Use the unified **`phpc`** CLI from the repo root (wrapper around `bin/vm.php`, `bin/compile.php`, `bin/lint.php`, and `bin/serve.php`).

## Smoke status (v1.1.0 prep, Jun 2026)

| Gate | Status | Command / tracker |
|------|--------|-------------------|
| VM lint + run (000–009) | ✅ default | `./phpc test --fast`, `make web-smoke` |
| Web serve + curls | ✅ default | `./script/examples-web-smoke.sh` ([#9226](https://github.com/PurHur/php-compiler/issues/9226)) |
| AOT link + execute | 🚧 partial | `./script/examples-aot-smoke.sh` ([#9225](https://github.com/PurHur/php-compiler/issues/9225)); 000–004 + 006–009 green when LLVM ready; 007 execute under investigation |

See roadmap [#78](https://github.com/PurHur/php-compiler/issues/78) for v1.1.0 release gates.

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

./phpc lint examples/006-FileUploadWeb/example.php
./phpc run examples/006-FileUploadWeb/example.php

./phpc lint examples/007-ThrowsWeb/example.php
./phpc run examples/007-ThrowsWeb/example.php

./phpc lint examples/008-SelfHostProbe/example.php
./phpc run examples/008-SelfHostProbe/example.php

./phpc lint examples/009-FastCGIWeb/example.php
./phpc run examples/009-FastCGIWeb/example.php
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
| [005-SessionsWeb](005-SessionsWeb/) | ✅ `./phpc run` / `phpc serve` | ✅ `session_start` JIT ([#1882](https://github.com/PurHur/php-compiler/issues/1882)) | ✅ `phpc build` link ([#1946](https://github.com/PurHur/php-compiler/issues/1946)); execute [#1891](https://github.com/PurHur/php-compiler/issues/1891); deploy smoke opt-in ([#1893](https://github.com/PurHur/php-compiler/issues/1893)) | `$_SESSION` flash across requests — [#1881](https://github.com/PurHur/php-compiler/issues/1881) |
| [006-FileUploadWeb](006-FileUploadWeb/) | ✅ `./phpc run` / `phpc serve` | ✅ nested `$_FILES` JIT ([#87](https://github.com/PurHur/php-compiler/issues/87)) | ✅ `phpc build` link ([#2011](https://github.com/PurHur/php-compiler/issues/2011)); execute default-on ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) | `multipart/form-data` + `$_FILES['doc']` — [#1999](https://github.com/PurHur/php-compiler/issues/1999) |
| [007-ThrowsWeb](007-ThrowsWeb/) | ✅ `./phpc run` / `phpc serve` | ✅ `phpc serve --jit` caught POST ([#2435](https://github.com/PurHur/php-compiler/issues/2435), [#2408](https://github.com/PurHur/php-compiler/issues/2408)) | ✅ `phpc build` + CGI execute | `throw` / `catch` on invalid POST — [#2076](https://github.com/PurHur/php-compiler/issues/2076); VM smoke `THROWS_WEB_SMOKE_GATE=1` default ([#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2125](https://github.com/PurHur/php-compiler/issues/2125)); AOT `THROWSWEB_AOT_*_GATE=1` default ([#2135](https://github.com/PurHur/php-compiler/issues/2135)) |
| [008-SelfHostProbe](008-SelfHostProbe/) | ✅ `./phpc run` | — | ✅ AOT CLI ([#2407](https://github.com/PurHur/php-compiler/issues/2407)) | North Star 2 presenter — [#2207](https://github.com/PurHur/php-compiler/issues/2207); `make north-star2-verify` / `SELFHOSTPROBE_AOT_SMOKE_GATE=1`; epic [#1492](https://github.com/PurHur/php-compiler/issues/1492) |
| [009-FastCGIWeb](009-FastCGIWeb/) | ✅ `./phpc run` / `phpc serve` | 📋 deferred | ✅ `phpc build --project` | Health + CGI diagnostics — [#2331](https://github.com/PurHur/php-compiler/issues/2331); FastCGI execute [#173](https://github.com/PurHur/php-compiler/issues/173); deploy [#635](https://github.com/PurHur/php-compiler/issues/635) |
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
../../phpc serve --jit 127.0.0.1:8080 examples/001-SimpleWeb   # MCJIT per script (#207, #2274)
SERVE_JIT_SMOKE_GATE=1 make examples-serve-jit-smoke            # 001/003/007 phpc serve --jit curls (#2274, #2478)
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

See [003-MiniWebApp/README.md](003-MiniWebApp/README.md) for routes and gate ladder (`make miniwebapp-gates`). AOT deploy quickstart: [docs/deploy-web-aot.md](../docs/deploy-web-aot.md). OOP subset rows (`ClassMethod`, `Expr_MethodCall`, `__construct`): [docs/capabilities-syntax.md](../docs/capabilities-syntax.md#oop-reference-examples003-miniwebapp) ([#2190](https://github.com/PurHur/php-compiler/issues/2190)).

### 004-ApiJson

Minimal JSON API (`json_encode`, `http_response_code`) ([#270](https://github.com/PurHur/php-compiler/issues/270)).

```console
./phpc lint examples/004-ApiJson/example.php
./phpc run examples/004-ApiJson/example.php
./phpc serve 127.0.0.1:8080 examples/004-ApiJson
curl -s -D - 'http://127.0.0.1:8080/example.php'
```

Init scaffold: `./phpc init --profile apijson my-api` ([#2000](https://github.com/PurHur/php-compiler/issues/2000)); template parity: [#2029](https://github.com/PurHur/php-compiler/issues/2029).

### 005-SessionsWeb

`session_start()` plus a POST → redirect → GET flash message ([#1881](https://github.com/PurHur/php-compiler/issues/1881)). VM run shows the empty state; use `phpc serve` and a cookie jar for two-request persistence (presenter copy-paste: [docs/GETTING-STARTED.md](../docs/GETTING-STARTED.md) § 5; detail: [005-SessionsWeb/README.md](005-SessionsWeb/README.md)).

```console
./phpc lint examples/005-SessionsWeb/example.php
./phpc run examples/005-SessionsWeb/example.php
./phpc serve 127.0.0.1:8080 examples/005-SessionsWeb
jar=/tmp/phpc-sessionsweb.jar
curl -s -c "$jar" 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" -c "$jar" -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" 'http://127.0.0.1:8080/example.php'
```

AOT link/execute: [#1891](https://github.com/PurHur/php-compiler/issues/1891). AOT deploy + `PHPC_DEPLOY_ROOT` CGI flash: `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 005` ([#1893](https://github.com/PurHur/php-compiler/issues/1893)). VM session curls: `SESSIONS_WEB_SMOKE_GATE=1` (default) in `ci-fast.sh` / `ci-local.sh` — `make examples-sessions-smoke` or `examples-web-smoke.sh --sessions-only` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)). Init scaffold: `./phpc init --profile sessionsweb my-app` ([#1886](https://github.com/PurHur/php-compiler/issues/1886)); template parity: [#1902](https://github.com/PurHur/php-compiler/issues/1902). Gate ladder in `phpc doctor --gates`: [#1903](https://github.com/PurHur/php-compiler/issues/1903).

### 006-FileUploadWeb

`multipart/form-data` upload with nested `$_FILES['doc']` ([#1999](https://github.com/PurHur/php-compiler/issues/1999)). VM run shows the empty state; use `phpc serve` and `curl -F` for upload smoke (presenter copy-paste: [docs/GETTING-STARTED.md](../docs/GETTING-STARTED.md) § 5a; detail: [006-FileUploadWeb/README.md](006-FileUploadWeb/README.md)).

```console
./phpc lint examples/006-FileUploadWeb/example.php
./phpc run examples/006-FileUploadWeb/example.php
./phpc serve 127.0.0.1:8080 examples/006-FileUploadWeb
curl -s -F 'doc=@examples/006-FileUploadWeb/README.md' http://127.0.0.1:8080/example.php
```

VM multipart curls: `make examples-web-smoke` / `ci-fast` (default `FILE_UPLOAD_WEB_SMOKE_GATE=1`, [#2009](https://github.com/PurHur/php-compiler/issues/2009)). AOT link: default `FILE_UPLOAD_WEB_AOT_LINK_GATE=1` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)); AOT execute: default `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` ([#2012](https://github.com/PurHur/php-compiler/issues/2012)); shell slice `EXAMPLES_AOT_SMOKE_ONLY=006 make examples-aot-smoke` ([#2013](https://github.com/PurHur/php-compiler/issues/2013)) — see [006-FileUploadWeb/README.md](006-FileUploadWeb/README.md). Init scaffold: `./phpc init --profile fileupload my-upload` ([#2004](https://github.com/PurHur/php-compiler/issues/2004)); template parity: [#2020](https://github.com/PurHur/php-compiler/issues/2020) (default in `ci-fast`).

### 007-ThrowsWeb

POST email validation with `throw` / `catch` ([#2076](https://github.com/PurHur/php-compiler/issues/2076)). Invalid input renders an **invalid** message; valid input shows **Accepted**.

```console
./phpc lint examples/007-ThrowsWeb/example.php
./phpc run examples/007-ThrowsWeb/example.php
./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb
curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid
```

VM serve curls: `make examples-throws-smoke` or `make examples-web-smoke` (`THROWS_WEB_SMOKE_GATE=1` default — [#2125](https://github.com/PurHur/php-compiler/issues/2125), [#2093](https://github.com/PurHur/php-compiler/issues/2093)). Presenter copy-paste: [docs/GETTING-STARTED.md](../docs/GETTING-STARTED.md) § 5b ([#2158](https://github.com/PurHur/php-compiler/issues/2158)). AOT link/execute: default `THROWSWEB_AOT_*_GATE=1` ([#2135](https://github.com/PurHur/php-compiler/issues/2135), [#2157](https://github.com/PurHur/php-compiler/issues/2157)); JIT deferred ([#2167](https://github.com/PurHur/php-compiler/issues/2167)). Init scaffold: `./phpc init --profile throwsweb my-app` ([#2092](https://github.com/PurHur/php-compiler/issues/2092)); template parity: [#2086](https://github.com/PurHur/php-compiler/issues/2086) (`INIT_THROWSWEB_PARITY_GATE=1` default in `ci-fast`, [#2127](https://github.com/PurHur/php-compiler/issues/2127)).

### 008-SelfHostProbe

North Star 2 self-host **presenter** — prints M0–M4 copy-paste commands ([#2207](https://github.com/PurHur/php-compiler/issues/2207)). Does not run bootstrap from PHP; use `make` targets. GETTING-STARTED §6: [#2222](https://github.com/PurHur/php-compiler/issues/2222).

```console
make examples-selfhostprobe-smoke
make north-star2-verify
BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke
```

See [008-SelfHostProbe/README.md](008-SelfHostProbe/README.md) and [docs/bootstrap-selfhost.md](../docs/bootstrap-selfhost.md). AOT: `SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh` ([#2407](https://github.com/PurHur/php-compiler/issues/2407)). Init scaffold: `./phpc init --profile selfhostprobe my-probe` ([#2220](https://github.com/PurHur/php-compiler/issues/2220)); template parity: `INIT_SELFHOSTPROBE_PARITY_GATE=1` in `ci-fast`. Spine slices: [#2201](https://github.com/PurHur/php-compiler/issues/2201), [#2134](https://github.com/PurHur/php-compiler/issues/2134); unit probe: [#2216](https://github.com/PurHur/php-compiler/issues/2216).

### 009-FastCGIWeb

Health check (`ok`) plus plain-text CGI diagnostics for non-root paths ([#2331](https://github.com/PurHur/php-compiler/issues/2331)). Use `phpc serve` until the FastCGI adapter ([#173](https://github.com/PurHur/php-compiler/issues/173)) lands; package with `phpc deploy` per [deploy-web-aot.md](../docs/deploy-web-aot.md).

```console
./phpc lint examples/009-FastCGIWeb/example.php
./phpc run examples/009-FastCGIWeb/example.php
./phpc serve 127.0.0.1:8080 examples/009-FastCGIWeb
curl -s http://127.0.0.1:8080/example.php
curl -s http://127.0.0.1:8080/example.php/ping
```

AOT: `phpc build --project examples/009-FastCGIWeb` when LLVM is ready. Init scaffold: `./phpc init --profile fastcgiweb my-app` ([#2342](https://github.com/PurHur/php-compiler/issues/2342)); template parity: `INIT_FASTCGIWEB_PARITY_GATE=1` in `ci-fast`. CI serve smoke: default-on `FASTCGI_WEB_SMOKE_GATE=1` · `make examples-fastcgiweb-smoke` ([#2351](https://github.com/PurHur/php-compiler/issues/2351), [#2369](https://github.com/PurHur/php-compiler/issues/2369)).

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

**001-SimpleWeb**, **002-StaticWeb**, **004-ApiJson**, **005-SessionsWeb**, **006-FileUploadWeb**, **007-ThrowsWeb**, **008-SelfHostProbe**, and **009-FastCGIWeb** ship a minimal manifest beside `example.php` ([#274](https://github.com/PurHur/php-compiler/issues/274), [#2207](https://github.com/PurHur/php-compiler/issues/2207), [#2331](https://github.com/PurHur/php-compiler/issues/2331)):

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
make examples-fileupload-deploy-smoke   # 006-FileUploadWeb deploy CGI only (#2044)
make deploy-smoke-all       # 001–003 deploy + 005/006 when SESSIONS_WEB_DEPLOY_SMOKE_GATE / FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1; skip hints when gates=0 (#2077)
make examples-aot-smoke     # phpc build + CLI execute (000–004 + 006 when gate on; skips when LLVM missing; 003 execute green #764)
```

Full CI (`./script/ci-local.sh`) runs `examples-aot-smoke.sh` after PHPUnit `@group aot-link` when LLVM is available (`EXAMPLES_AOT_SMOKE_GATE=1` default in `script/ci-defaults.env`; set `EXAMPLES_AOT_SMOKE_GATE=0` to skip during iteration — [#674](https://github.com/PurHur/php-compiler/issues/674)). Not run in `ci-fast.sh`.

Full suite on the host (after `composer install`):

```console
./script/ci-local.sh
EXAMPLES_AOT_SMOKE_GATE=0 ./script/ci-local.sh   # skip 000–004 CLI AOT execute smoke (#674)
```

In Docker (preferred on harness hosts without host PHP/LLVM):

```console
make test-harness           # or: ./script/docker-ci-local.sh
./script/docker-exec.sh -- ./script/ci-local.sh
```

Root README quick start and local CI matrix: [#48](https://github.com/PurHur/php-compiler/issues/48), [#245](https://github.com/PurHur/php-compiler/issues/245).

## Benchmark results

Each example includes a benchmark that compares VM, JIT, and (when LLVM is present) AOT against native `php`. Regenerate this table with `script/rebuild-examples.php` ([#60](https://github.com/PurHur/php-compiler/issues/60)).

```console
MINIWEBAPP_LINT_GATE=1 ./script/rebuild-examples.php
# or: BENCH_MINIWEBAPP=1 ./script/rebuild-examples.php
# or: BENCH_SESSIONSWEB=1 ./script/rebuild-examples.php   # 005 row (#1889)
# or: BENCH_FILEUPLOADWEB=1 ./script/rebuild-examples.php   # 006 row (#2027)
# or: BENCH_THROWSWEB=1 ./script/rebuild-examples.php   # 007 row (#2113)
# or: BENCH_THROWSWEB=1 BENCH_THROWSWEB_AOT=1 ./script/rebuild-examples.php   # 007 AOT columns (#2166)
# or: BENCH_FASTCGIWEB=1 ./script/rebuild-examples.php   # 009 row (#2370)
# or: BENCH_FASTCGIWEB=1 BENCH_FASTCGIWEB_AOT=1 ./script/rebuild-examples.php   # 009 AOT columns (#2370)
```

For **001-SimpleWeb**, `bin/compile.php` is timed **without** compile-time `-q`; the `./compiled` column runs the binary with runtime `QUERY_STRING` (and related CGI env), matching production AOT web binaries.

For **003-MiniWebApp**, VM/JIT/native columns run `public/index.php` with `PATH_INFO=/home` (and related CGI env) from the example `public/` directory ([#491](https://github.com/PurHur/php-compiler/issues/491), runtime [#539](https://github.com/PurHur/php-compiler/issues/539)). AOT columns time `phpc build --project` and `.phpc/bin/app` with the same CGI overlay when LLVM is ready and execute returns HTML ([#716](https://github.com/PurHur/php-compiler/issues/716); execute [#764](https://github.com/PurHur/php-compiler/issues/764) closed). The row is omitted when `phpc lint --all examples/003-MiniWebApp` fails unless `BENCH_MINIWEBAPP=1`. A **project JIT** sub-row (`bin/jit.php index.php` from `public/`, same CGI overlay as `MiniWebAppJitProjectTest`) is added when `MINIWEBAPP_JIT_PROJECT_GATE=1 BENCH_MINIWEBAPP_JIT_PROJECT=1 ./script/rebuild-examples.php` ([#2183](https://github.com/PurHur/php-compiler/issues/2183), drift guard [#2334](https://github.com/PurHur/php-compiler/issues/2334)).

For **005-SessionsWeb**, the benchmark row is omitted until `phpc lint --all examples/005-SessionsWeb` passes unless `BENCH_SESSIONSWEB=1` ([#1889](https://github.com/PurHur/php-compiler/issues/1889)). AOT columns time `phpc build --project` and a two-request session flash on `.phpc/bin/app` when LLVM is ready ([#1891](https://github.com/PurHur/php-compiler/issues/1891), [#1973](https://github.com/PurHur/php-compiler/issues/1973)); use `BENCH_SESSIONSWEB_AOT=1 ./script/rebuild-examples.php` to force AOT columns on harness regen.

For **006-FileUploadWeb**, the benchmark row is omitted until `phpc lint --all examples/006-FileUploadWeb` passes unless `BENCH_FILEUPLOADWEB=1` ([#2027](https://github.com/PurHur/php-compiler/issues/2027)). VM/JIT/native columns use a multipart POST CGI overlay (same body as `FileUploadWebAotExecuteTest`). AOT columns time `phpc build --project` and a multipart upload probe on `.phpc/bin/app` when LLVM is ready ([#2011](https://github.com/PurHur/php-compiler/issues/2011), [#2012](https://github.com/PurHur/php-compiler/issues/2012)); use `BENCH_FILEUPLOADWEB_AOT=1 ./script/rebuild-examples.php` to force AOT columns on harness regen.

For **007-ThrowsWeb**, the benchmark row is omitted until `phpc lint --all examples/007-ThrowsWeb` passes unless `BENCH_THROWSWEB=1` ([#2113](https://github.com/PurHur/php-compiler/issues/2113)). VM/JIT/native columns use a POST `email=bad` CGI overlay (caught invalid path, same as `examples-web-smoke.sh --throws-only`). AOT columns time `phpc build --project` and an invalid POST probe on `.phpc/bin/app` when LLVM is ready ([#2157](https://github.com/PurHur/php-compiler/issues/2157), [#2166](https://github.com/PurHur/php-compiler/issues/2166)); use `BENCH_THROWSWEB=1 BENCH_THROWSWEB_AOT=1 ./script/rebuild-examples.php` to force AOT columns on harness regen. Set `BENCH_THROWSWEB_AOT=0` to omit AOT columns.

For **009-FastCGIWeb**, the benchmark row is omitted until `phpc lint --all examples/009-FastCGIWeb` passes unless `BENCH_FASTCGIWEB=1` ([#2370](https://github.com/PurHur/php-compiler/issues/2370)). VM/JIT/native columns use a `PATH_INFO=/ping` CGI overlay (same needles as `examples-web-smoke.sh --fastcgi-only` / `FastCGIWebAotExecuteTest`). AOT columns time `phpc build --project` and health + PATH_INFO probes on `.phpc/bin/app` when LLVM is ready ([#2352](https://github.com/PurHur/php-compiler/issues/2352)); use `BENCH_FASTCGIWEB=1 BENCH_FASTCGIWEB_AOT=1 ./script/rebuild-examples.php` to force AOT columns on harness regen. Set `BENCH_FASTCGIWEB_AOT=0` to omit AOT columns.

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.00790 |         0.06942 |         0.25238 |         3.43904 |         0.00111 |
|        001-SimpleWeb |         0.00815 |         0.07362 |         0.24972 |         3.48373 |         0.00127 |
|        002-StaticWeb |         0.00787 |         0.07071 |         0.25628 |         3.79083 |         0.00122 |
|       003-MiniWebApp |         0.00815 |         0.09864 |         0.24346 |             n/a |             n/a |
|          004-ApiJson |         0.00777 |         0.06538 |         0.25183 |         3.38246 |         0.00123 |
|      005-SessionsWeb |         0.01000 |         0.07074 |         0.26034 |             n/a |             n/a |
|    006-FileUploadWeb |         0.00808 |         0.04762 |         0.04799 |         5.30625 |         0.00100 |
|        007-ThrowsWeb |         0.00810 |         0.07519 |         0.07293 |         5.23089 |         0.00105 |
|    008-SelfHostProbe |         0.00835 |         0.07029 |         0.24949 |         3.35477 |         0.00120 |
|       009-FastCGIWeb |         0.00812 |         0.07063 |         0.26357 |         5.24895 |         0.00081 |
<!-- benchmark table end -->
