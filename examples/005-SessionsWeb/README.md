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
| AOT link/execute | 📋 [#1891](https://github.com/PurHur/php-compiler/issues/1891) |

## Template parity

`templates/init-sessionsweb/` stays byte-identical to this tree on key files ([#1902](https://github.com/PurHur/php-compiler/issues/1902), [#695](https://github.com/PurHur/php-compiler/issues/695) policy):

```console
./script/check-init-sessionsweb-parity.sh   # wired into ci-fast inventory checks
```

## Related

- [#1887](https://github.com/PurHur/php-compiler/issues/1887) — `SESSIONS_WEB_SMOKE_GATE=1` (default): `make examples-sessions-smoke`, `ci-fast.sh`, `ExamplesCompileTest::test005SessionsWebServeFlashRoundTrip`
- [#1893](https://github.com/PurHur/php-compiler/issues/1893) — deploy + `PHPC_DEPLOY_ROOT` smoke
- [#1886](https://github.com/PurHur/php-compiler/issues/1886) — `phpc init --profile sessionsweb` copies from `templates/init-sessionsweb/`
