# 005-SessionsWeb

Minimal session + flash message reference app ([#1881](https://github.com/PurHur/php-compiler/issues/1881)).

Uses `session_start()` and `$_SESSION['flash']` with a POST → redirect → GET flow when served over HTTP.

## Routes and session keys

| Method | Path | Behavior |
|--------|------|----------|
| GET | `/example.php` | Renders form; shows `$_SESSION['flash']` once then clears it |
| POST | `/example.php` | Sets `$_SESSION['flash']` from `message`, redirects 303 to GET |

Session file name: default `PHPSESSID` cookie from `phpc serve` (requires `PHP_COMPILER_SESSION_DIR` when set in CI).

## Run

```console
./phpc lint examples/005-SessionsWeb/example.php
./phpc run examples/005-SessionsWeb/example.php
./phpc serve 127.0.0.1:8080 examples/005-SessionsWeb
```

Two-request flash (cookie jar):

```console
jar=/tmp/phpc-sessionsweb.jar
curl -s -c "$jar" 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" -c "$jar" -X POST -d 'message=Saved' 'http://127.0.0.1:8080/example.php'
curl -s -b "$jar" 'http://127.0.0.1:8080/example.php'
```

The last response should include `Flash: Saved`.

## Status

| Layer | Notes |
|-------|-------|
| VM `phpc run` | ✅ single request (no flash until POST via serve) |
| VM `phpc serve` | ✅ with `PHP_COMPILER_SESSION_DIR` + cookie jar |
| JIT | ✅ `session_start` ([#1882](https://github.com/PurHur/php-compiler/issues/1882)) |
| AOT link | ✅ `ExamplesCompileTest::test005SessionsWebAotLink` ([#1946](https://github.com/PurHur/php-compiler/issues/1946); `SESSIONS_WEB_AOT_LINK_GATE=1` default) |
| AOT execute | 📋 `SessionsWebAotExecuteTest` ([#1891](https://github.com/PurHur/php-compiler/issues/1891); `SESSIONS_WEB_AOT_SMOKE_GATE=1`) |

## Template parity

`templates/init-sessionsweb/` stays byte-identical to this tree on key files ([#1902](https://github.com/PurHur/php-compiler/issues/1902), [#695](https://github.com/PurHur/php-compiler/issues/695) policy):

```console
./script/check-init-sessionsweb-parity.sh   # wired into ci-fast inventory checks
```

## Related

- [#1887](https://github.com/PurHur/php-compiler/issues/1887) — `SESSIONS_WEB_SMOKE_GATE=1` (default): `make examples-sessions-smoke`, `ci-fast.sh`, `ExamplesCompileTest::test005SessionsWebServeFlashRoundTrip`
- [#1946](https://github.com/PurHur/php-compiler/issues/1946) — `SESSIONS_WEB_AOT_LINK_GATE=1`: `ExamplesCompileTest::test005SessionsWebAotLink` (link-only; execute stays [#1891](https://github.com/PurHur/php-compiler/issues/1891))
- [#1893](https://github.com/PurHur/php-compiler/issues/1893) — deploy + `PHPC_DEPLOY_ROOT` smoke
- [#1886](https://github.com/PurHur/php-compiler/issues/1886) — `phpc init --profile sessionsweb` copies from `templates/init-sessionsweb/`
