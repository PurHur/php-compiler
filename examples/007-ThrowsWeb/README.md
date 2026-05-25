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
| VM `phpc serve` | ✅ caught invalid POST (`THROWS_WEB_SMOKE_GATE=1` opt-in, [#2093](https://github.com/PurHur/php-compiler/issues/2093)) |
| JIT / AOT | 📋 deferred — [#2101](https://github.com/PurHur/php-compiler/issues/2101) / [#2104](https://github.com/PurHur/php-compiler/issues/2104) |

## CI gates

Defaults from `script/ci-defaults.env` (VM smoke **off** until stable):

```console
./phpc doctor --gates | grep -E 'THROWS_WEB|007-ThrowsWeb'
```

| Stage | Gate | Default | Command when enabled |
|-------|------|---------|----------------------|
| VM serve | `THROWS_WEB_SMOKE_GATE` | `0` | `THROWS_WEB_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only` ([#2093](https://github.com/PurHur/php-compiler/issues/2093)) |

## Related

- [#195](https://github.com/PurHur/php-compiler/issues/195) — `throw` lowering
- [#57](https://github.com/PurHur/php-compiler/issues/57) — try/catch
- [#2084](https://github.com/PurHur/php-compiler/issues/2084) — compliance PHPT pack
