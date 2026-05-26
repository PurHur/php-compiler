# 006-FileUploadWeb

Minimal `multipart/form-data` upload reference ([#1999](https://github.com/PurHur/php-compiler/issues/1999)).

Reads nested `$_FILES['doc']` after POST and echoes filename + byte size. Reuses VM/AOT superglobal refresh from [#52](https://github.com/PurHur/php-compiler/issues/52) and [#87](https://github.com/PurHur/php-compiler/issues/87).

## Run

```console
./phpc lint examples/006-FileUploadWeb/example.php
./phpc run examples/006-FileUploadWeb/example.php
./phpc serve 127.0.0.1:8080 examples/006-FileUploadWeb
curl -s -F 'doc=@examples/006-FileUploadWeb/README.md' http://127.0.0.1:8080/example.php
```

The curl response should include `Uploaded: README.md` and a non-zero byte count.

## Status

| Layer | Notes |
|-------|-------|
| VM `phpc run` | ✅ GET — shows empty state |
| VM `phpc serve` | ✅ multipart POST (`FILE_UPLOAD_WEB_SMOKE_GATE=1` default, [#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| AOT link | ✅ `ExamplesCompileTest::test006FileUploadWebAotLink` (`FILE_UPLOAD_WEB_AOT_LINK_GATE=1` default, [#2011](https://github.com/PurHur/php-compiler/issues/2011)) |
| AOT execute | ✅ `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` default ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) — CGI `REQUEST_BODY` multipart |
| AOT deploy + CGI upload | ✅ `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1` ([#2028](https://github.com/PurHur/php-compiler/issues/2028)) |

## Deploy smoke (AOT + PHPC_DEPLOY_ROOT)

```console
make examples-fileupload-deploy-smoke
# or: FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 ../../script/deploy-smoke.sh --example 006
```

Builds with `phpc build --project`, deploys to `.phpc/smoke/deploy/006-FileUploadWeb`, runs `bin/app` under `PHPC_DEPLOY_ROOT` with a multipart `doc` upload (README.md). Expect `Uploaded: README.md` in the response body.

## CI gates

Defaults from `script/ci-defaults.env` (VM smoke + AOT link + AOT execute default-on):

```console
./phpc doctor --gates | grep -E 'FILE_UPLOAD_WEB|006-FileUploadWeb'
```

| Stage | Gate | Default | Command when enabled |
|-------|------|---------|----------------------|
| VM multipart | `FILE_UPLOAD_WEB_SMOKE_GATE` | `1` | `./script/examples-web-smoke.sh --fileupload-only` or `ci-fast` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| AOT serve | `FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE` | `0` | `FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=1 ./script/examples-web-smoke.sh --fileupload-only --aot` ([#2333](https://github.com/PurHur/php-compiler/issues/2333)) |
| AOT link | `FILE_UPLOAD_WEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test006FileUploadWebAotLink` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)) |
| AOT execute | `FILE_UPLOAD_WEB_AOT_SMOKE_GATE` | `1` | `./script/ci-local.sh --filter FileUploadWebAotExecuteTest` or `EXAMPLES_AOT_SMOKE_ONLY=006 ./script/examples-aot-smoke.sh` ([#2012](https://github.com/PurHur/php-compiler/issues/2012), [#2013](https://github.com/PurHur/php-compiler/issues/2013)) |
| Deploy CGI | `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE` | `0` | `make examples-fileupload-deploy-smoke` ([#2044](https://github.com/PurHur/php-compiler/issues/2044)); `ci-local` opt-in ([#2038](https://github.com/PurHur/php-compiler/issues/2038)) |

## Related

- [#1999](https://github.com/PurHur/php-compiler/issues/1999) — this example tree
- [#52](https://github.com/PurHur/php-compiler/issues/52) — multipart `$_POST`
- [#87](https://github.com/PurHur/php-compiler/issues/87) — nested `$_FILES`
- [#2028](https://github.com/PurHur/php-compiler/issues/2028) — deploy-smoke multipart CGI
