# 009-FastCGIWeb

Minimal multi-request fixture for **`phpc deploy`** + future FastCGI adapter ([#173](https://github.com/PurHur/php-compiler/issues/173)). Tracks [#2331](https://github.com/PurHur/php-compiler/issues/2331).

| Route | Response |
|-------|----------|
| `GET /example.php` (empty `PATH_INFO`) | `ok` (health) |
| `GET /example.php/...` (non-empty `PATH_INFO`) | Plain-text `REQUEST_URI`, `SCRIPT_NAME`, `PATH_INFO` |

## Run (VM / serve)

```console
./phpc lint examples/009-FastCGIWeb/example.php
./phpc run examples/009-FastCGIWeb/example.php
./phpc serve 127.0.0.1:8080 examples/009-FastCGIWeb
curl -s http://127.0.0.1:8080/example.php
curl -s http://127.0.0.1:8080/example.php/ping
```

## AOT + deploy

```console
cd examples/009-FastCGIWeb
../../phpc build --project .
QUERY_STRING= REQUEST_URI=/example.php SCRIPT_NAME=/example.php ./.phpc/bin/app
../../phpc deploy --project . /tmp/fastcgiweb-dist
```

Production nginx + `PHPC_DEPLOY_ROOT`: [docs/deploy-web-aot.md](../../docs/deploy-web-aot.md) ([#445](https://github.com/PurHur/php-compiler/issues/445)). Long-lived FastCGI loop: [#173](https://github.com/PurHur/php-compiler/issues/173).

## Status

| Layer | Notes |
|-------|-------|
| VM `phpc run` | ✅ health `ok` |
| VM `phpc serve` | ✅ health + `/ping` diagnostics |
| AOT `phpc build --project` | ✅ when LLVM ready (`ExamplesCompileTest`) |
| AOT CGI execute | ✅ opt-in `FASTCGI_WEB_AOT_SMOKE_GATE=1` ([#2352](https://github.com/PurHur/php-compiler/issues/2352)); `EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh` |
| FastCGI adapter execute | 📋 blocked on [#173](https://github.com/PurHur/php-compiler/issues/173) |
| CI serve smoke | ✅ opt-in `FASTCGI_WEB_SMOKE_GATE=1` ([#2351](https://github.com/PurHur/php-compiler/issues/2351)); `make examples-fastcgiweb-smoke` |
| Deploy CGI smoke | ✅ opt-in `FASTCGI_WEB_DEPLOY_SMOKE_GATE=1` ([#2359](https://github.com/PurHur/php-compiler/issues/2359)); `FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 009` |

## Related

- [#635](https://github.com/PurHur/php-compiler/issues/635) — `phpc deploy`
- [#173](https://github.com/PurHur/php-compiler/issues/173) — FastCGI request loop
- [examples/README.md](../README.md) — shipped ladder
