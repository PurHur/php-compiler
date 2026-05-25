# 007-ThrowsWeb

Minimal `throw` / `catch` web presenter ([#2076](https://github.com/PurHur/php-compiler/issues/2076)). POST with a bad email throws `ValidationError`; the catch block renders a friendly **invalid** message. Uncaught exceptions from `phpc serve` map to HTTP 500 ([#152](https://github.com/PurHur/php-compiler/issues/152)).

## Run

```console
./phpc lint examples/007-ThrowsWeb/example.php
./phpc run examples/007-ThrowsWeb/example.php
./phpc serve 127.0.0.1:8080 examples/007-ThrowsWeb
curl -sf -X POST -d 'email=bad' http://127.0.0.1:8080/example.php | grep -i invalid
```

## Status

| Layer | Notes |
|-------|-------|
| VM `phpc run` | ✅ GET — empty state |
| VM `phpc serve` | ✅ caught invalid POST (`THROWS_WEB_SMOKE_GATE=1` default, [#2093](https://github.com/PurHur/php-compiler/issues/2093), [#2125](https://github.com/PurHur/php-compiler/issues/2125)) |
| VM uncaught → HTTP 500 | ✅ `uncaught.php` + opt-in `THROWSWEB_UNCAUGHT_500_GATE=1` ([#2200](https://github.com/PurHur/php-compiler/issues/2200), [#152](https://github.com/PurHur/php-compiler/issues/152)) |
| AOT `phpc build` + execute | ✅ caught invalid POST ([#2157](https://github.com/PurHur/php-compiler/issues/2157), [#2135](https://github.com/PurHur/php-compiler/issues/2135)) |
| JIT | 📋 deferred — [#2167](https://github.com/PurHur/php-compiler/issues/2167) |

## CI gates

Defaults from `script/ci-defaults.env`:

```console
./phpc doctor --gates | grep -E 'THROWS_WEB|007-ThrowsWeb'
```

| Stage | Gate | Default | Command |
|-------|------|---------|---------|
| VM serve | `THROWS_WEB_SMOKE_GATE` | `1` | `make examples-throws-smoke` · `ci-fast.sh` ([#2125](https://github.com/PurHur/php-compiler/issues/2125)) |
| VM uncaught 500 | `THROWSWEB_UNCAUGHT_500_GATE` | `0` | `THROWSWEB_UNCAUGHT_500_GATE=1 ./script/examples-web-smoke.sh --throws-only` ([#2200](https://github.com/PurHur/php-compiler/issues/2200)) |
| AOT link | `THROWSWEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test007ThrowsWebAotLink` ([#2135](https://github.com/PurHur/php-compiler/issues/2135)) |
| AOT execute | `THROWSWEB_AOT_SMOKE_GATE` | `1` | `ThrowsWebAotExecuteTest` · `EXAMPLES_AOT_SMOKE_ONLY=007 make examples-aot-smoke` ([#2135](https://github.com/PurHur/php-compiler/issues/2135)) |

Opt-out for doc-only iteration: `THROWS_WEB_SMOKE_GATE=0 THROWSWEB_AOT_LINK_GATE=0 THROWSWEB_AOT_SMOKE_GATE=0 ./script/ci-fast.sh`

## Benchmark row

Regenerate `examples/README.md` timings when lint is green:

```console
BENCH_THROWSWEB=1 ./script/rebuild-examples.php
grep '007-ThrowsWeb' examples/README.md
```

Omitted by default until `phpc lint --all examples/007-ThrowsWeb` passes (same gate as **005** / **006**). See [#2113](https://github.com/PurHur/php-compiler/issues/2113).

## Related

- [#195](https://github.com/PurHur/php-compiler/issues/195) — `throw` lowering
- [#57](https://github.com/PurHur/php-compiler/issues/57) — try/catch
- [#2084](https://github.com/PurHur/php-compiler/issues/2084) — compliance PHPT pack
