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

### 3. Web app in the VM (primary dev)

```bash
./phpc serve examples/003-MiniWebApp
```

Open `http://127.0.0.1:8080/` — home, hello (`/index.php/hello/world`), contact POST, JSON API (`/index.php/api/status`). **Talking point:** VM `phpc serve` is the day-to-day path; same routes also execute under native AOT + CGI ([#764](https://github.com/PurHur/php-compiler/issues/764) closed). This fixture is an **integration test**, not a project north star — see [#1492](https://github.com/PurHur/php-compiler/issues/1492). Deeper commands: [examples/003-MiniWebApp/README.md](../examples/003-MiniWebApp/README.md) ([#1531](https://github.com/PurHur/php-compiler/issues/1531)); root README + status site sync: [#1525](https://github.com/PurHur/php-compiler/issues/1525).

### 4. (Optional) Native AOT for MiniWebApp

Requires LLVM (see prerequisites). From repo root:

```bash
./phpc lint --all examples/003-MiniWebApp
./phpc build --project examples/003-MiniWebApp
./phpc run --project examples/003-MiniWebApp --cgi-env-file test/fixtures/cgi-env/miniwebapp-home.env
```

**Talking point:** `phpc run --project` drives the linked binary with CGI env — no TCP. With LLVM present you can also try `./phpc serve examples/003-MiniWebApp --aot` for HTTP over the native binary.

Layout-edge AOT bisect polish ([#1750](https://github.com/PurHur/php-compiler/issues/1750)) is opt-in and does not block the execute story above.

### 5. (Optional) SessionsWeb — two-request flash (VM)

`session_start()` and `$_SESSION['flash']` across HTTP requests ([#1881](https://github.com/PurHur/php-compiler/issues/1881)). `./phpc run` shows the empty form only; use `phpc serve` and a cookie jar for the POST → redirect → GET story.

```bash
./phpc serve examples/005-SessionsWeb
jar=/tmp/phpc-sessionsweb.jar
curl -s -c "$jar" 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" -c "$jar" -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" 'http://127.0.0.1:8080/example.php'   # expect Flash: Saved
```

**Talking point:** Real session cookies without Zend PHP at dev time — same pattern as production CGI apps. Automated curls: `make examples-sessions-smoke` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)). Gate ladder: `./phpc doctor --gates | grep -i sessions` ([#1903](https://github.com/PurHur/php-compiler/issues/1903)). AOT two-request execute ([#1891](https://github.com/PurHur/php-compiler/issues/1891)) and deploy smoke ([#1893](https://github.com/PurHur/php-compiler/issues/1893)) are opt-in when LLVM is green — see [examples/005-SessionsWeb/README.md](../examples/005-SessionsWeb/README.md) and ROADMAP [#78](https://github.com/PurHur/php-compiler/issues/78).

### 5a. (Optional) FileUploadWeb — multipart upload (VM)

`multipart/form-data` and nested `$_FILES['doc']` ([#1999](https://github.com/PurHur/php-compiler/issues/1999)). `./phpc run` shows the empty state only; use `phpc serve` and `curl -F` for the upload story.

```bash
./phpc serve examples/006-FileUploadWeb
curl -s -F 'doc=@examples/006-FileUploadWeb/README.md' http://127.0.0.1:8080/example.php
```

**Talking point:** Real file upload handling without Zend PHP at dev time — same `$_FILES` path as production CGI. Automated curls: `make examples-web-smoke` / `FILE_UPLOAD_WEB_SMOKE_GATE=1 ./script/examples-web-smoke.sh` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)). Gate ladder: `./phpc doctor --gates | grep -i file_upload` ([#2010](https://github.com/PurHur/php-compiler/issues/2010)). AOT execute ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) and deploy smoke ([#2028](https://github.com/PurHur/php-compiler/issues/2028), [#2038](https://github.com/PurHur/php-compiler/issues/2038)) are opt-in when LLVM is green — see [examples/006-FileUploadWeb/README.md](../examples/006-FileUploadWeb/README.md) and ROADMAP [#78](https://github.com/PurHur/php-compiler/issues/78).

### 5b. (Optional) ThrowsWeb — caught invalid POST (VM)

`throw` / `catch` on a bad form field ([#2076](https://github.com/PurHur/php-compiler/issues/2076)). `./phpc run` shows the empty form only; use `phpc serve` and a POST with invalid email for the caught error HTML.

```bash
./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb
curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid
```

**Talking point:** Caught exceptions in a web form without Zend PHP at dev time — same `throw` / `catch` path as production CGI. Automated curls: `make examples-throws-smoke` ([#2141](https://github.com/PurHur/php-compiler/issues/2141), [#2125](https://github.com/PurHur/php-compiler/issues/2125), [#2093](https://github.com/PurHur/php-compiler/issues/2093)). Uncaught throws map to HTTP 500 ([#152](https://github.com/PurHur/php-compiler/issues/152)); opt-in smoke: `THROWSWEB_UNCAUGHT_500_GATE=1 ./script/examples-web-smoke.sh --throws-only` ([#2200](https://github.com/PurHur/php-compiler/issues/2200)). Gate ladder: `./phpc doctor --gates | grep -i throws` ([#2102](https://github.com/PurHur/php-compiler/issues/2102)). Native AOT link/execute for user-defined `ValidationError` is green ([#2157](https://github.com/PurHur/php-compiler/issues/2157); `THROWSWEB_AOT_*_GATE=1` default — [#2135](https://github.com/PurHur/php-compiler/issues/2135), [#2101](https://github.com/PurHur/php-compiler/issues/2101)) — see [examples/007-ThrowsWeb/README.md](../examples/007-ThrowsWeb/README.md) and ROADMAP [#78](https://github.com/PurHur/php-compiler/issues/78).

### 6. (Optional) Self-host smoke

```bash
script/apply-patches.sh
make bootstrap-selfhost-link
```

Expect stdout: `compiler_minimal bundle OK`. **Talking point:** experimental path toward the compiler compiling its own `lib/` tree ([#1492](https://github.com/PurHur/php-compiler/issues/1492)).

### 7. (Optional) North Star 4 — M4 strict bootstrap loop

M4 presenter ladder: inventory clean → M3 strict probes → gen-1 link → loop probe (gen-1→gen-2 + gen-2→gen-3 spine) ([#2379](https://github.com/PurHur/php-compiler/issues/2379), [#2464](https://github.com/PurHur/php-compiler/issues/2464)). Generation ladder: [docs/bootstrap-generations.md](bootstrap-generations.md). Sibling: [008-SelfHostProbe](../examples/008-SelfHostProbe/README.md) (North Star 2/3); detail: [docs/bootstrap-selfhost.md](bootstrap-selfhost.md) · [docs/self-host-target.md](self-host-target.md).

```bash
./phpc lint --bootstrap-inventory --check
make north-star4-verify
# partial M4 (probe --dry-run; exits 0 when M3 strict still blocks):
./script/north-star4-verify.sh --dry-run-only
# when LLVM 9 is present — fail on partial M4:
./script/north-star4-verify.sh --strict --require-llvm
./phpc doctor --selfhost
```

**Talking point:** Default `north-star4-verify` exits **0** on partial M4 (documented M3 strict / gen-2 / gen-3 blockers) so presenters can show the ladder without a red demo; `--strict` is for contributors chasing green M4. When LLVM is present and gen-1 link is green, step 6 runs `bootstrap-loop-gen2-recompile-spine` unless the full probe already passed gen-3.

On Runforge / harness hosts (do **not** use raw `docker run -v "$(pwd):/compiler"`):

```bash
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./phpc lint --bootstrap-inventory --check'
./script/docker-exec.sh -- bash -lc 'make north-star4-verify'
./script/docker-exec.sh -- bash -lc './script/north-star4-verify.sh --dry-run-only'
```

**Next steps:** `BOOTSTRAP_M4_GEN2_STRICT_GATE=1` in `ci-local.sh` ([#2112](https://github.com/PurHur/php-compiler/issues/2112)); opt-in `NORTH_STAR4_VERIFY_GATE=1` ([#2429](https://github.com/PurHur/php-compiler/issues/2429)); argv `bin/compile.php -o` on compiled driver ([#1937](https://github.com/PurHur/php-compiler/issues/1937), [#1521](https://github.com/PurHur/php-compiler/issues/1521)); full revision rebuild ([#1498](https://github.com/PurHur/php-compiler/issues/1498)).

### 8. (Optional) Full local CI

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
| `./phpc run --project . --cgi-env-file env` | Execute linked binary with CGI env (no TCP) |
| `./phpc serve <docroot> --aot` | HTTP dev server over native binary (LLVM) |
| `./phpc deploy -o dist/` | Package binary + `public/` for CGI deploy |
| `./phpc lint --all path/` | Unsupported-syntax report |
| `./phpc test` / `--fast` | Full / fast local CI |
| `./phpc init --profile miniwebapp dir/` | Scaffold MiniWebApp layout |
| `./phpc init --profile apijson dir/` | Scaffold 004-ApiJson layout (flat `example.php`) |
| `./phpc init --profile sessionsweb dir/` | Scaffold 005-SessionsWeb layout (flat `example.php`) |
| `./phpc init --profile fileupload dir/` | Scaffold 006-FileUploadWeb layout (flat `example.php`) |
| `./phpc doctor` | Environment + optional gate probe |
| `./phpc doctor --gates` | Example web gates + self-host presenter steps ([#1752](https://github.com/PurHur/php-compiler/issues/1752), [#1857](https://github.com/PurHur/php-compiler/issues/1857), [#1871](https://github.com/PurHur/php-compiler/issues/1871)); 005 ladder: `grep -i sessions` ([#1903](https://github.com/PurHur/php-compiler/issues/1903)); 006 ladder: `grep -i file_upload` ([#2010](https://github.com/PurHur/php-compiler/issues/2010)); 007 ThrowsWeb: `grep -i throws` ([#2102](https://github.com/PurHur/php-compiler/issues/2102)) |
| `make north-star1-verify` | Example web regression bundle (legacy name; [#1044](https://github.com/PurHur/php-compiler/issues/1044) closed) ([#1845](https://github.com/PurHur/php-compiler/issues/1845)) |
| `make north-star2-verify` | Self-host M0–M4 presenter bundle ([#1865](https://github.com/PurHur/php-compiler/issues/1865); listed in `phpc doctor --gates` when script exists) |
| `make north-star3-verify` | M3 native unit probe bundle — 008 + compiler/JIT/VM/parser/PHPTypes probes ([#2360](https://github.com/PurHur/php-compiler/issues/2360); [#2216](https://github.com/PurHur/php-compiler/issues/2216) / [#2332](https://github.com/PurHur/php-compiler/issues/2332) / [#2354](https://github.com/PurHur/php-compiler/issues/2354) / [#2418](https://github.com/PurHur/php-compiler/issues/2418) / [#2434](https://github.com/PurHur/php-compiler/issues/2434)) |
| `make north-star4-verify` | M4 strict bootstrap-loop presenter — inventory + M3 strict + gen-1 link + loop probe + gen-2→gen-3 ([#2379](https://github.com/PurHur/php-compiler/issues/2379)); `--dry-run-only` on partial M4; opt-in CI [#2429](https://github.com/PurHur/php-compiler/issues/2429) |
| `make north-star5-verify` | M5 presenter — inventory + spine + vendor prelink **3/3** + committed `.o` cold boot without `vendor/` ([#1416](https://github.com/PurHur/php-compiler/issues/1416)); Zend still default for empty `build/` |
| `make bootstrap-loop-gen2-recompile-spine` | Gen-2 native driver recompiles **726/726** spine → gen-3 without Zend on compile ([#2697](https://github.com/PurHur/php-compiler/pull/2697), [#2866](https://github.com/PurHur/php-compiler/issues/2866)) |
| `make bootstrap-selfhost-full-revision-probe` | M4 full revision — gen-2 argv compile of `bin/compile.php` → gen-3 (still 🚧 `parseAndCompile` null — [#2633](https://github.com/PurHur/php-compiler/issues/2633), [#2880](https://github.com/PurHur/php-compiler/issues/2880)) |
| `make deploy-smoke-all` | Full `PHPC_DEPLOY_ROOT` deploy ladder 001–003 + opt-in 005/006; skip hints when gates `0` — see `./phpc doctor --gates` ([#2077](https://github.com/PurHur/php-compiler/issues/2077)) |

Legacy entrypoints (`bin/vm.php`, `bin/jit.php`, `bin/compile.php`) still work.

## Documentation guide

| Path | Audience | Content |
|------|----------|---------|
| [README.md](../README.md) | Everyone | Quick start, north star + CI |
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
| MiniWebApp AOT gates unclear | `./phpc doctor --gates` (see [miniwebapp-gates.md](miniwebapp-gates.md); default-on flips: [#1760](https://github.com/PurHur/php-compiler/issues/1760)) |

More: [README § Troubleshooting](../README.md#troubleshooting).

## Updating public docs after a milestone

1. Edit [`docs/pages/development-status.md`](pages/development-status.md) (authoritative written status — **no links** to capability/inventory/CI matrices).
2. Adjust [`docs/pages/index.html`](pages/index.html) progress bars / badges if the composite % changed.
3. Sync north-star / example-gate tables in [README.md](../README.md).

**Contributors only (not part of the public site):** regenerate `capabilities.md` / `capabilities-syntax.md` when builtins change (`php script/capability-matrix.php`, `php script/capability-syntax.php`).

Publish: see [`docs/pages/PAGES.md`](pages/PAGES.md).
