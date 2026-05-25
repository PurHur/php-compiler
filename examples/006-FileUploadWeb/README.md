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
| AOT execute | ✅ opt-in `FILE_UPLOAD_WEB_AOT_SMOKE_GATE=1` — CGI `REQUEST_BODY` multipart |

## CI gates

Defaults from `script/ci-defaults.env` (VM smoke + AOT link default-on; AOT execute opt-in):

```console
./phpc doctor --gates | grep -E 'FILE_UPLOAD_WEB|006-FileUploadWeb'
```

| Stage | Gate | Default | Command when enabled |
|-------|------|---------|----------------------|
| VM multipart | `FILE_UPLOAD_WEB_SMOKE_GATE` | `1` | `./script/examples-web-smoke.sh --fileupload-only` or `ci-fast` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| AOT link | `FILE_UPLOAD_WEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test006FileUploadWebAotLink` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)) |
| AOT execute | `FILE_UPLOAD_WEB_AOT_SMOKE_GATE` | `0` | `./script/ci-local.sh --filter test006FileUploadWebMultipartAotExecute` ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) |

## Related

- [#1999](https://github.com/PurHur/php-compiler/issues/1999) — this example tree
- [#52](https://github.com/PurHur/php-compiler/issues/52) — multipart `$_POST`
- [#87](https://github.com/PurHur/php-compiler/issues/87) — nested `$_FILES`
