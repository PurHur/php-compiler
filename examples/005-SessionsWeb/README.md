# 005-SessionsWeb

Minimal session + flash message reference app ([#1881](https://github.com/PurHur/php-compiler/issues/1881)).

Uses `session_start()` and `$_SESSION['flash']` with a POST → redirect → GET flow when served over HTTP.

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
| AOT deploy + CGI flash | ✅ `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1` ([#1893](https://github.com/PurHur/php-compiler/issues/1893)) |
| AOT link/execute (standalone) | 📋 [#1891](https://github.com/PurHur/php-compiler/issues/1891) |

Deploy + two-request CGI (no HTTP server):

```console
export PHP_COMPILER_SESSION_DIR=/tmp/phpc-sessions
mkdir -p "$PHP_COMPILER_SESSION_DIR"
../../phpc build --project .
../../phpc deploy . -o /tmp/sessions-dist
export PHPC_DEPLOY_ROOT=/tmp/sessions-dist
# See script/deploy-smoke.sh --example 005 for CGI env + cookie jar pattern
SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ../../script/deploy-smoke.sh --example 005
```

## Related

- [#1887](https://github.com/PurHur/php-compiler/issues/1887) — `SESSIONS_WEB_SMOKE_GATE=1` (default): `make examples-sessions-smoke`, `ci-fast.sh`, `ExamplesCompileTest::test005SessionsWebServeFlashRoundTrip`
- [#1893](https://github.com/PurHur/php-compiler/issues/1893) — `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1`: `deploy-smoke.sh --example 005`, `docs/deploy-web-aot.md`
