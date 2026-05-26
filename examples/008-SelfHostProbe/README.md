# 008-SelfHostProbe

North Star 2 **presenter** for self-host bootstrap M0–M4 ([#2207](https://github.com/PurHur/php-compiler/issues/2207)). Prints a copy-paste ladder; it does **not** invoke `make` or compile `lib/` from PHP (use the Makefile targets below).

Epic: [#1492](https://github.com/PurHur/php-compiler/issues/1492) · Presenter script: `script/north-star2-verify.sh` ([#1865](https://github.com/PurHur/php-compiler/issues/1865)) · Detail: [docs/bootstrap-selfhost.md](../../docs/bootstrap-selfhost.md)

## Run

```console
make examples-selfhostprobe-smoke
# or:
./phpc lint examples/008-SelfHostProbe/example.php
./phpc run examples/008-SelfHostProbe/example.php
```

Output includes `SelfHostProbe` and `north-star2-verify` (VM smoke in `ExamplesCompileTest`).

## Presenter ladder

```console
make north-star2-verify
BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke
php script/bootstrap-inventory.php --check
./phpc doctor --selfhost
```

On Runforge / harness hosts (do **not** use raw `docker run -v "$(pwd):/compiler"`):

```console
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./phpc run examples/008-SelfHostProbe/example.php'
./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && ./phpc build -o /tmp/probe examples/008-SelfHostProbe/example.php && /tmp/probe'
./script/docker-exec.sh -- bash -lc 'make north-star2-verify'
./script/docker-exec.sh -- bash -lc 'make north-star3-verify'
```

M3 native unit probes (LLVM; skips when absent): `make north-star3-verify` ([#2360](https://github.com/PurHur/php-compiler/issues/2360); parser [#2418](https://github.com/PurHur/php-compiler/issues/2418), PHPTypes [#2434](https://github.com/PurHur/php-compiler/issues/2434)).

AOT (LLVM required):

```console
./phpc build -o .phpc/bin/probe example.php
./.phpc/bin/probe
# or:
SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh
```

## Status

| Layer | Notes |
|-------|-------|
| VM `phpc run` | ✅ presenter text only — no superglobals required |
| VM `phpc serve` | 📋 not used (CLI presenter) |
| AOT | ✅ `phpc build` + native execute ([#2407](https://github.com/PurHur/php-compiler/issues/2407)); `SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh` |
| CI | ✅ VM: `ExamplesCompileTest` ([#2239](https://github.com/PurHur/php-compiler/issues/2239)); `EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=1` in `ci-fast` ([#2343](https://github.com/PurHur/php-compiler/issues/2343)); AOT: `SELFHOSTPROBE_AOT_SMOKE_GATE=1` ([#2407](https://github.com/PurHur/php-compiler/issues/2407)) |

## Next slices

- **#2201** — `bin/vm.php` native link + execute smoke
- **#2134** — deferred spine paths (`lib/AOT/Linker.php`, …)
- **#2216** — `lib/Compiler.php` unit compile-driver probe
- **#2220** ✅ — `phpc init --profile selfhostprobe` scaffold (`./script/check-init-selfhostprobe-parity.sh`)
- **#2222** — [docs/GETTING-STARTED.md](../../docs/GETTING-STARTED.md) §6 walkthrough
