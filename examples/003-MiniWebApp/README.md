# 003-MiniWebApp

Reference web app: skeleton [#67](https://github.com/PurHur/php-compiler/issues/67) closed ([#246](https://github.com/PurHur/php-compiler/issues/246)); VM/runtime tracker [#539](https://github.com/PurHur/php-compiler/issues/539); routing [#210](https://github.com/PurHur/php-compiler/issues/210). `phpc serve` and lint are green; PATH_INFO URLs in [#489](https://github.com/PurHur/php-compiler/issues/489); AOT link ✅ ([#752](https://github.com/PurHur/php-compiler/issues/752)); native execute [#764](https://github.com/PurHur/php-compiler/issues/764). VM/JIT/AOT matrix for PATH_INFO, deploy includes, and CGI: [capabilities-syntax.md § Web north-star](../../docs/capabilities-syntax.md#web-north-star-examples003-miniwebapp) ([#655](https://github.com/PurHur/php-compiler/issues/655)).

## Init template parity

`phpc init --profile miniwebapp` scaffolds from `templates/init-miniwebapp/`. Key app files (`public/index.php`, `src/Router.php`, `config.php`, `phpc.json`, templates, `assets/style.css`) must stay **byte-identical** to this directory ([#695](https://github.com/PurHur/php-compiler/issues/695)).

```console
./script/check-init-miniwebapp-parity.sh   # wired into ci-fast inventory checks
```

Update both trees in one PR when changing routes or templates. Rare intentional drift: use `// miniwebapp-parity: intentional divergence — <reason>` in **both** files.

## Layout

```
examples/003-MiniWebApp/
  README.md
  phpc.json              # entry public/index.php, includes[] (#452)
  config.php
  public/index.php       # PATH_INFO + ?route= fallback (#489)
  src/Router.php         # class dispatch (VM/JIT; AOT execute #764)
  templates/             # layout + partials (__DIR__ includes)
  assets/style.css
```

## Lint

```console
./phpc lint --all examples/003-MiniWebApp
```

Exits `0` (class methods, includes, `break`, and superglobals are accepted). `make web-smoke` fails when lint regresses (gate on by default — [#621](https://github.com/PurHur/php-compiler/issues/621)). Skeleton debugging only:

```console
MINIWEBAPP_LINT_GATE=0 make web-smoke
```

## Routes

| Method | URL | Behavior |
|--------|-----|----------|
| GET | `/index.php` or `/` | Home |
| GET | `/index.php/hello?name=` | Greet |
| POST | `/index.php/contact` | Form thank-you (`name` required, max 200 chars — [#697](https://github.com/PurHur/php-compiler/issues/697)) |
| GET | `/index.php/api/status` | JSON status |

Deprecated query dispatch (still supported):

| Method | URL |
|--------|-----|
| GET | `/index.php?route=home` |
| GET | `/index.php?route=hello&name=` |
| POST | `/index.php?route=contact` |
| GET | `/index.php?route=api/status` |

## AOT debug without serve (#774)

After `phpc build --project .` (LLVM), run the native binary with CGI env — no TCP:

```console
../../phpc run --project . --cgi-env QUERY_STRING=route=home --cgi-env REQUEST_METHOD=GET
../../phpc run --project . --cgi-env-file ../../test/fixtures/cgi-env/miniwebapp-home.env
../../phpc run --project . --cgi-env-file ../../test/fixtures/cgi-env/miniwebapp-home.env --require-nonempty-stdout
```

`--require-nonempty-stdout` exits `2` when stdout is empty (useful while [#764](https://github.com/PurHur/php-compiler/issues/764) execute is broken). With `phpc deploy -o /tmp/dist`, add `--deploy-root /tmp/dist`.

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `./phpc lint --all .` |
| VM serve | ✅ | `./phpc serve 127.0.0.1:8080 .` from this directory |
| Shell smoke | ✅ | `../../script/examples-web-smoke.sh` (after lint green) |
| Shell smoke (ci-local) | ✅ | `../../script/ci-local.sh` (`MINIWEBAPP_WEB_SMOKE_GATE=1` default; `=0` to skip — #664) |
| PHPUnit serve | ✅ | `ServeTest` `@group miniwebapp` (#470) |
| JIT | partial | [#207](https://github.com/PurHur/php-compiler/issues/207) |
| AOT link | ✅ | `../../phpc build --project .` when LLVM ready (`MINIWEBAPP_AOT_LINK_GATE=1` default — [#754](https://github.com/PurHur/php-compiler/issues/754)) |
| AOT execute | ❌ | Native `.phpc/bin/app` stdout empty until [#764](https://github.com/PurHur/php-compiler/issues/764); opt-in `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#791](https://github.com/PurHur/php-compiler/issues/791)) |

### curl recipes (PATH_INFO)

```console
cd examples/003-MiniWebApp
../../phpc serve 127.0.0.1:8080 .
curl -s 'http://127.0.0.1:8080/index.php'
curl -s 'http://127.0.0.1:8080/index.php/hello?name=Dev'
curl -s -X POST -d 'name=PostDev' 'http://127.0.0.1:8080/index.php/contact'
curl -s 'http://127.0.0.1:8080/index.php/api/status'
```

Query fallback:

```console
curl -s 'http://127.0.0.1:8080/index.php?route=home'
```

## CI gate ladder

Progressive stages from `script/miniwebapp-gates.sh` / `make miniwebapp-gates`:

| Stage | Check | Status |
|-------|--------|--------|
| 1 | `phpc lint --all` | ✅ green |
| 1b | `MINIWEBAPP_VM_CLI_GATE=1` in ci-fast | ✅ default on |
| 2 | `ServeTest` `@group miniwebapp` | ✅ default on |
| 3 | `examples-web-smoke.sh` 003 curls | ✅ wired |
| 3b | `MINIWEBAPP_WEB_SMOKE_GATE=1` shell smoke | ✅ default on |
| 4a | `phpc build --project --dry-run` | probe (LLVM) |
| 4c | `EXAMPLES_AOT_SMOKE_ONLY=003` smoke slice | skip until AOT execute [#764](https://github.com/PurHur/php-compiler/issues/764) ([#683](https://github.com/PurHur/php-compiler/issues/683)) |
| 4b | `ExamplesCompileTest::test003MiniWebAppBuildLinks` | ✅ link gate ([#754](https://github.com/PurHur/php-compiler/issues/754)) |
| 4b2 | `test003MiniWebAppExecutesWithCgiEnv` | opt-in `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#791](https://github.com/PurHur/php-compiler/issues/791), blocked [#764](https://github.com/PurHur/php-compiler/issues/764)) |
| 4b2 bisect | `script/miniwebapp-aot-bisect.sh` ordered PHPT ladder | opt-in `MINIWEBAPP_AOT_BISECT_GATE=1` ([#879](https://github.com/PurHur/php-compiler/issues/879), [#764](https://github.com/PurHur/php-compiler/issues/764)) |

Stage **4c** runs only the 003 block of `script/examples-aot-smoke.sh` (same pass/skip/fail UX as 4a). Full examples smoke: `make examples-aot-smoke`.

Ordered **#764** AOT fixture bisect (smallest failing step first):

```console
./script/miniwebapp-aot-bisect.sh --list
./script/miniwebapp-aot-bisect.sh
./script/miniwebapp-aot-bisect.sh --from nested_include_two_tier
MINIWEBAPP_AOT_BISECT_INCLUDE_APP=1 ./script/miniwebapp-aot-bisect.sh
MINIWEBAPP_AOT_BISECT_GATE=1 make miniwebapp-gates
```

## CI hooks

```console
../../phpc doctor --gates          # same ladder as miniwebapp-gates.sh (#657)
make miniwebapp-gates
../../script/examples-web-smoke.sh
MINIWEBAPP_VM_CLI_GATE=1 ../../script/ci-fast.sh --filter 'MiniWebApp.*VmCli'
../../script/ci-local.sh --filter ServeTest
MINIWEBAPP_SERVE_GATE=0 ../../script/ci-local.sh   # skip miniwebapp ServeTest while iterating
MINIWEBAPP_WEB_SMOKE_GATE=0 ../../script/ci-local.sh   # skip 003 shell PATH_INFO curls (#664)
MINIWEBAPP_AOT_LINK_GATE=0 ../../script/ci-local.sh --filter ExamplesCompileTest   # skip 003 link gate (#754)
MINIWEBAPP_AOT_EXECUTE_GATE=1 ../../script/ci-local.sh --filter test003MiniWebAppExecutesWithCgiEnv   # after #764
../../script/miniwebapp-aot-bisect.sh --list   # #764 ladder (#879)
MINIWEBAPP_AOT_BISECT_GATE=1 ../../script/miniwebapp-gates.sh
```

Fast CI runs `MiniWebAppVmCliTest` and `MiniWebAppPathInfoVmCliTest` when `MINIWEBAPP_VM_CLI_GATE=1` (default). Set `MINIWEBAPP_VM_CLI_GATE=0` to skip the VM CLI matrix during iteration.

Full CI runs `ServeTest` `@group miniwebapp` with `--fail-on-skipped` when `MINIWEBAPP_SERVE_GATE=1` (default on in `ci-local.sh` / `ci-fast.sh`; set `MINIWEBAPP_SERVE_GATE=0` to skip during iteration — [#641](https://github.com/PurHur/php-compiler/issues/641)).

Full CI runs `script/examples-web-smoke.sh --miniwebapp-only` after serve PHPUnit when `MINIWEBAPP_WEB_SMOKE_GATE=1` (default on in `ci-local.sh`; set `MINIWEBAPP_WEB_SMOKE_GATE=0` to skip during iteration — [#664](https://github.com/PurHur/php-compiler/issues/664)). Not run in `ci-fast.sh`. Skips when `PHP_COMPILER_SKIP_SERVE_TESTS=1` or loopback bind fails (same as `ServeTest`).

Oversized POST body limit (stage 3, [#705](https://github.com/PurHur/php-compiler/issues/705)): after PATH_INFO curls, `examples-web-smoke.sh` starts `phpc serve` with `PHP_COMPILER_MAX_BODY=1024` (override via env) and POSTs a body larger than the limit to `/index.php/contact`; the step fails if HTTP status is `200` (expect `413` or connection reset). Manual probe:

```console
PHP_COMPILER_MAX_BODY=1024 ../../script/examples-web-smoke.sh --miniwebapp-only
```

- [#503](https://github.com/PurHur/php-compiler/issues/503) — gate ladder
- [#597](https://github.com/PurHur/php-compiler/issues/597) — `MINIWEBAPP_VM_CLI_GATE` in `ci-fast.sh`
- [#586](https://github.com/PurHur/php-compiler/issues/586) — `?route=` VM CLI matrix
- [#595](https://github.com/PurHur/php-compiler/issues/595) — PATH_INFO VM CLI matrix
- [#764](https://github.com/PurHur/php-compiler/issues/764) — native AOT execute (empty stdout; `ExamplesCompileTest` still skipped)
- [#454](https://github.com/PurHur/php-compiler/issues/454) — umbrella `ExamplesCompileTest` AOT tracker
- [#461](https://github.com/PurHur/php-compiler/issues/461) — `examples-web-smoke.sh` curls
- [#470](https://github.com/PurHur/php-compiler/issues/470) — `ServeTest` `@group miniwebapp`
- [#622](https://github.com/PurHur/php-compiler/issues/622) — `MINIWEBAPP_SERVE_GATE` in `ci-local.sh`
- [#641](https://github.com/PurHur/php-compiler/issues/641) — default `MINIWEBAPP_SERVE_GATE=1` in full/fast CI
- [#633](https://github.com/PurHur/php-compiler/issues/633) — `MINIWEBAPP_WEB_SMOKE_GATE` in `ci-local.sh`
- [#664](https://github.com/PurHur/php-compiler/issues/664) — default `MINIWEBAPP_WEB_SMOKE_GATE=1` in full CI
- [#705](https://github.com/PurHur/php-compiler/issues/705) — oversized POST check in `examples-web-smoke.sh`
- [#675](https://github.com/PurHur/php-compiler/issues/675) — stage 4a AOT dry-run in gate ladder
- [#683](https://github.com/PurHur/php-compiler/issues/683) — stage 4c `examples-aot-smoke` 003 slice probe

## Related

- [#246](https://github.com/PurHur/php-compiler/issues/246) — skeleton
- [#489](https://github.com/PurHur/php-compiler/issues/489) — PATH_INFO URLs
