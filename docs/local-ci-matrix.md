# Local CI matrix

How to run the php-compiler test gate on a developer machine or Runforge harness **without GitHub Actions** ([#245](https://github.com/PurHur/php-compiler/issues/245), [#394](https://github.com/PurHur/php-compiler/issues/394)).

## Defaults

Repository defaults live in [`script/ci-defaults.env`](../script/ci-defaults.env):

| Variable | Default | Role |
|----------|---------|------|
| `PHP_COMPILER_MEMORY_LIMIT` | `1536M` | PHP heap for PHPUnit and `bin/vm.php` children |
| `PHP_COMPILER_LLVM_MEMORY_LIMIT` | `4096M` | LLVM compile phases in `ci-local.sh` |
| `PHP_COMPILER_CI_RAM_GB` | `8` | `ulimit -v` for the CI shell |
| `PHP_COMPILER_DOCKER_MEM` | `10g` | Docker cgroup RAM cap |
| `PHP_COMPILER_VM_PEAK_RSS_MB` | `2048` | Kill VM subprocess if RSS exceeds this (when guard enabled) |
| `PHP_COMPILER_VM_RSS_GUARD` | `1` in CI | Wrap PHPT `vm.php` spawns with `run-vm-guarded.sh` |

## Entry points

| Goal | Host PHP + LLVM | Docker (recommended on harness) |
|------|-----------------|-------------------------------|
| Full gate (VM + JIT + AOT) | `./script/ci-local.sh` | `make test` or `./script/docker-ci-local.sh` |
| Fast gate (no LLVM compile) | `./script/ci-fast.sh` | `make test-fast` or `./script/docker-ci-local.sh fast` |
| Fast gate + JIT preflight (optional) | `JIT_PREFLIGHT_GATE=1 ./script/ci-fast.sh` | `make test-fast-jit-preflight` or `make test-docker-fast-jit-preflight` |
| Explicit memory-capped Docker | — | `./script/ci-docker-safe.sh ci-local.sh` or `make test-docker-safe` |
| Single PHPUnit filter | Append args: `./script/ci-fast.sh --filter VMTest` | Same inside Docker wrappers |

## Memory safety

- **One full CI container at a time.** Parallel `make test` runs can exhaust RAM ([#497](https://github.com/PurHur/php-compiler/issues/497)).
- All Docker CI paths use `script/ci-docker-run.sh` (`-m 10g` + env from `ci-defaults.env`).
- PHPT compliance tests monitor `bin/vm.php` RSS in `BaseTest` when `PHP_COMPILER_VM_RSS_GUARD=1` (default); manual profiling uses `script/run-vm-guarded.sh` ([#500](https://github.com/PurHur/php-compiler/issues/500)).
- Profile PHPT peak RSS locally: `./script/scan-vm-phpt-peak-rss.sh [dir] [limit]`.

## Environment overrides

```bash
export PHP_COMPILER_MEMORY_LIMIT=4G
export PHP_COMPILER_CI_RAM_GB=16
export PHP_COMPILER_DOCKER_MEM=16g
export PHP_COMPILER_VM_PEAK_RSS_MB=4096
export PHP_COMPILER_VM_RSS_GUARD=0   # disable RSS killer (debug only)
make test
```

### JIT preflight on fast CI ([#728](https://github.com/PurHur/php-compiler/issues/728))

`ci-fast` skips `@group llvm` tests, so a broken `LD_LIBRARY_PATH` with LLVM present may only surface at the end of `ci-local` ([#250](https://github.com/PurHur/php-compiler/issues/250)). Enable an early MCJIT probe after `composer install`:

```bash
JIT_PREFLIGHT_GATE=1 ./script/ci-fast.sh
# or: make test-fast-jit-preflight
# Docker: JIT_PREFLIGHT_GATE=1 ./script/docker-ci-local.sh fast
```

Standalone probe (same logic as `phpc doctor --jit-probe`):

```bash
php script/check-jit-compliance-ran.php --preflight
```

Exits **0** when LLVM 9 is missing (nothing to guard). Exits **non-zero** when LLVM is present but PHPLLVM/MCJIT cannot bootstrap ([#98](https://github.com/PurHur/php-compiler/issues/98)). Default **off** until contributors opt in.

**Unlimited memory is blocked:** `PHP_COMPILER_MEMORY_LIMIT=-1` and `memory_limit=-1` in tracked files fail `script/check-no-unlimited-memory.sh` (run at CI start).

**Stale closed-issue blockers:** `script/check-stale-issue-refs.sh` fails when closed issues (e.g. **#568**, **#67**) still appear as active blockers in `script/`, `lib/Cli/`, `examples/`, and `docs/deploy-web-aot.md`. Opt out per line with `# stale-issue-ok: <reason>`. Wired in `ci-fast.sh` / `ci-local.sh` inventory via `script/ci-common.sh` ([#802](https://github.com/PurHur/php-compiler/issues/802)).

## Serve tests

Set `PHP_COMPILER_SKIP_SERVE_TESTS=1` only when loopback TCP bind is unavailable. Harness Docker CI should **not** set this by default.

## Docker dev image (issue [#202](https://github.com/PurHur/php-compiler/issues/202))

Harness hosts and contributors without host PHP/LLVM should use the **22.04 dev image**, not legacy `ircmaxell/php-compiler:*` tags (Docker Hub 404).

| Step | Command |
|------|---------|
| Build locally | `make docker-build-22` → `php-compiler:22.04-dev` |
| Smoke | `docker run --rm php-compiler:22.04-dev php -v` |
| Full CI in container | `make test-docker` or `./script/docker-ci-local.sh` |
| Harness empty bind-mount | `./script/docker-ci-local.sh` (tar fallback, [#272](https://github.com/PurHur/php-compiler/issues/272)) |
| Fast CI in container | `./script/docker-ci-local.sh fast` |
| Pull (optional) | `docker pull ghcr.io/PurHur/php-compiler:dev` then `export PHP_COMPILER_DEV_IMAGE=ghcr.io/PurHur/php-compiler:dev` |
| Publish (maintainer) | `docker login ghcr.io` then `./script/docker-publish-dev.sh --push` or `make docker-publish-dev` |

`make docker-build-22` tags both `php-compiler:22.04-dev` and `ghcr.io/PurHur/php-compiler:dev`. CI wrappers default to the local tag unless `PHP_COMPILER_DEV_IMAGE` is set.

## Related issues

- [#436](https://github.com/PurHur/php-compiler/issues/436) — tiered CI (`ci-fast` vs `ci-local`)
- [#728](https://github.com/PurHur/php-compiler/issues/728) — optional `JIT_PREFLIGHT_GATE` on `ci-fast`
- [#497](https://github.com/PurHur/php-compiler/issues/497) — memory incident (closed)
- [#500](https://github.com/PurHur/php-compiler/issues/500) — VM RSS profiling
- [#501](https://github.com/PurHur/php-compiler/issues/501) — land CI caps (closed via PR)
- [#802](https://github.com/PurHur/php-compiler/issues/802) — stale closed-issue blocker strings in scripts/docs
- [#202](https://github.com/PurHur/php-compiler/issues/202) — Docker dev image docs + optional ghcr.io publish
