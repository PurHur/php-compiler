# 003-MiniWebApp

Reference web app: skeleton [#67](https://github.com/PurHur/php-compiler/issues/67) closed ([#246](https://github.com/PurHur/php-compiler/issues/246)); VM/runtime tracker [#539](https://github.com/PurHur/php-compiler/issues/539); routing [#210](https://github.com/PurHur/php-compiler/issues/210). `phpc serve` and lint are green; PATH_INFO URLs in [#489](https://github.com/PurHur/php-compiler/issues/489); AOT execute [#454](https://github.com/PurHur/php-compiler/issues/454).

## Layout

```
examples/003-MiniWebApp/
  README.md
  phpc.json              # entry public/index.php, includes[] (#452)
  config.php
  public/index.php       # PATH_INFO + ?route= fallback (#489)
  src/Router.php         # class dispatch (VM/JIT; AOT pending #454)
  templates/             # layout + partials (__DIR__ includes)
  assets/style.css
```

## Lint

```console
./phpc lint --all examples/003-MiniWebApp
```

Exits `0` (class methods, includes, `break`, and superglobals are accepted). Optional strict gate:

```console
MINIWEBAPP_LINT_GATE=1 make web-smoke
```

## Routes

| Method | URL | Behavior |
|--------|-----|----------|
| GET | `/index.php` or `/` | Home |
| GET | `/index.php/hello?name=` | Greet |
| POST | `/index.php/contact` | Form thank-you |
| GET | `/index.php/api/status` | JSON status |

Deprecated query dispatch (still supported):

| Method | URL |
|--------|-----|
| GET | `/index.php?route=home` |
| GET | `/index.php?route=hello&name=` |
| POST | `/index.php?route=contact` |
| GET | `/index.php?route=api/status` |

## Run matrix

| Mode | Status | Command |
|------|--------|---------|
| Lint | ✅ | `./phpc lint --all .` |
| VM serve | ✅ | `./phpc serve 127.0.0.1:8080 .` from this directory |
| Shell smoke | ✅ | `../../script/examples-web-smoke.sh` (after lint green) |
| PHPUnit serve | ✅ | `ServeTest` `@group miniwebapp` (#470) |
| JIT | partial | [#207](https://github.com/PurHur/php-compiler/issues/207) |
| AOT | ❌ blocked | `../../phpc build --project .` ([#454](https://github.com/PurHur/php-compiler/issues/454)) |

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

## CI hooks

```console
make miniwebapp-gates
../../script/examples-web-smoke.sh
MINIWEBAPP_VM_CLI_GATE=1 ../../script/ci-fast.sh --filter 'MiniWebApp.*VmCli'
```

Fast CI runs `MiniWebAppVmCliTest` and `MiniWebAppPathInfoVmCliTest` when `MINIWEBAPP_VM_CLI_GATE=1` (default). Set `MINIWEBAPP_VM_CLI_GATE=0` to skip the VM CLI matrix during iteration.

- [#503](https://github.com/PurHur/php-compiler/issues/503) — gate ladder
- [#597](https://github.com/PurHur/php-compiler/issues/597) — `MINIWEBAPP_VM_CLI_GATE` in `ci-fast.sh`
- [#586](https://github.com/PurHur/php-compiler/issues/586) — `?route=` VM CLI matrix
- [#595](https://github.com/PurHur/php-compiler/issues/595) — PATH_INFO VM CLI matrix
- [#454](https://github.com/PurHur/php-compiler/issues/454) — `ExamplesCompileTest` AOT execute (still skipped)
- [#461](https://github.com/PurHur/php-compiler/issues/461) — `examples-web-smoke.sh` curls
- [#470](https://github.com/PurHur/php-compiler/issues/470) — `ServeTest` `@group miniwebapp`

## Related

- [#246](https://github.com/PurHur/php-compiler/issues/246) — skeleton
- [#489](https://github.com/PurHur/php-compiler/issues/489) — PATH_INFO URLs
