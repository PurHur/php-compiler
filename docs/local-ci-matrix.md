# Local CI matrix

How to run the php-compiler test gate on a developer machine or Runforge harness ([#245](https://github.com/PurHur/php-compiler/issues/245)).

**Remote CI is temporarily disabled:**

- **GitHub Actions** — [`.github/workflows-disabled/`](../.github/workflows-disabled/)
- **CircleCI** — [`.circleci-disabled/config.yml`](../.circleci-disabled/config.yml) (no active `.circleci/config.yml`)

Use **local/Docker commands below** as the merge gate until remote CI is restored ([#394](https://github.com/PurHur/php-compiler/issues/394)). Disabled workflow mirrors live under [`.github/workflows-disabled/`](../.github/workflows-disabled/).

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
| Fast gate + bootstrap tail (optional) | `CI_FAST_BOOTSTRAP=1 ./script/ci-fast.sh` | `make test-fast-bootstrap` |
| Fast gate + JIT preflight (optional) | `JIT_PREFLIGHT_GATE=1 ./script/ci-fast.sh` | `make test-fast-jit-preflight` or `make test-docker-fast-jit-preflight` |
| Explicit memory-capped Docker | — | `./script/ci-docker-safe.sh ci-local.sh` or `make test-docker-safe` |
| Single PHPUnit filter | Append args: `./script/ci-fast.sh --filter VMTest` | Same inside Docker wrappers |

## GitHub Actions: bootstrap self-host (disabled mirror)

Workflow (inactive): [`.github/workflows-disabled/bootstrap-selfhost.yml`](../.github/workflows-disabled/bootstrap-selfhost.yml). When re-enabled, triggers on **push** and **pull_request** to `master` (timeout **30 minutes**). **Do not rely on this for merge** — run the local equivalents below.

| Step | Command |
|------|---------|
| Checkout | `actions/checkout@v4` |
| Bootstrap probe | `make bootstrap-selfhost-probe` |
| Native link | `./script/bootstrap-selfhost-link.sh` |
| Wave gate | `./script/bootstrap-wave-check.sh --fail-fast` |

**Runner strategy** (historical; for re-enable only)

| Path | When | Setup |
|------|------|-------|
| Docker (default on `ubuntu-22.04` runners) | `docker info` succeeds | Build or reuse `php-compiler:22.04-dev` (`make docker-build-22` locally; GHA used the same Dockerfile) |
| Host fallback | Docker unavailable | Ubuntu 22.04 + `ppa:ondrej/php` PHP 8.2 + `./script/install-llvm9.sh` |

Both paths run `composer install`, `script/apply-patches.sh`, then the three bootstrap gates above. This workflow does **not** change default `ci-local.sh` / `ci-fast.sh` behavior locally.

**Env vars** (inherited from Docker image / `script/ci-defaults.env` when relevant):

| Variable | Role in workflow |
|----------|------------------|
| `PHP_COMPILER_LLVM_PATH` | `/opt/llvm9` in Docker; repo `.llvm/` after `install-llvm9.sh` on host fallback |
| `PHP_COMPILER_MEMORY_LIMIT` | PHP heap during compile (default `1536M`) |
| `PHP_COMPILER_LLVM_MEMORY_LIMIT` | LLVM compile phases (default `4096M`) |
| `PHP_COMPILER_SELFHOST_AOT` | Set by probe/link scripts for stub gating |
| `BOOTSTRAP_WAVE_CHECK` | N/A in workflow (always runs wave-check); set `0` in `ci-local.sh` to skip locally |

**Local equivalent (Docker)** — canonical today:

```bash
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev bash -lc \
  'make bootstrap-selfhost-probe && ./script/bootstrap-selfhost-link.sh && ./script/bootstrap-wave-check.sh'
```

**Local equivalent (host, no Docker):** `composer install`, `./script/install-llvm9.sh`, then the same three commands.

**Former GHA llvm job** (full VM + JIT + AOT gate): reproduce with `./script/ci-local.sh` or `make test-docker` (see Entry points above). Fast iteration without LLVM link: `./script/ci-fast.sh` or `make test-fast`.

## MiniWebApp gates ([#472](https://github.com/PurHur/php-compiler/issues/472), [#664](https://github.com/PurHur/php-compiler/issues/664))

Defaults are exported from [`script/ci-defaults.env`](../script/ci-defaults.env) and read by `ci-local.sh`, `ci-fast.sh`, and helpers in [`script/ci-common.sh`](../script/ci-common.sh). For the progressive stage ladder (lint → serve → AOT link → execute), see **[miniwebapp-gates.md](miniwebapp-gates.md)** ([#472](https://github.com/PurHur/php-compiler/issues/472)); probe status with [`script/miniwebapp-gates.sh`](../script/miniwebapp-gates.sh), `make miniwebapp-gates`, or `phpc doctor --gates`. **Presenter bundle** (gates + `ci-fast` MiniWebApp + AOT execute + optional AOT web-smoke): [`script/north-star1-verify.sh`](../script/north-star1-verify.sh) / `make north-star1-verify` ([#1845](https://github.com/PurHur/php-compiler/issues/1845), [#1044](https://github.com/PurHur/php-compiler/issues/1044)).

| Variable | Default | Script | Notes |
|----------|---------|--------|-------|
| `MINIWEBAPP_VM_CLI_GATE` | `1` | `ci-fast.sh` | PHPUnit `MiniWebApp*VmCli` matrix ([#597](https://github.com/PurHur/php-compiler/issues/597)) |
| `MINIWEBAPP_SERVE_GATE` | `1` | `ci-local.sh`, `ci-fast.sh` | `ServeTest` `@group miniwebapp` ([#641](https://github.com/PurHur/php-compiler/issues/641)) |
| `MINIWEBAPP_WEB_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-web-smoke.sh --miniwebapp-only` ([#664](https://github.com/PurHur/php-compiler/issues/664)) |
| `MINIWEBAPP_WEB_SMOKE_AOT_GATE` | `1` | `ci-local.sh` | `ci_run_miniwebapp_web_smoke_aot` → `examples-web-smoke.sh --miniwebapp-only --aot` ([#1523](https://github.com/PurHur/php-compiler/issues/1523), [#833](https://github.com/PurHur/php-compiler/issues/833)) |
| `MINIWEBAPP_AOT_LINK_GATE` | `1` | `ci-local.sh` (PHPUnit `@group aot-link`) | `ExamplesCompileTest` 003 native link ([#754](https://github.com/PurHur/php-compiler/issues/754)) |
| `MINIWEBAPP_AOT_EXECUTE_GATE` | `1` | `ci-local.sh` after `@group aot-link` (`ci_run_miniwebapp_aot_execute`) | PHPUnit `@group miniwebapp-aot-execute` / `MiniWebAppAotExecuteTest` ([#747](https://github.com/PurHur/php-compiler/issues/747), [#791](https://github.com/PurHur/php-compiler/issues/791)) |
| `EXAMPLES_AOT_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-aot-smoke.sh` after LLVM phases ([#674](https://github.com/PurHur/php-compiler/issues/674)) |
| `EXAMPLES_AOT_SMOKE_ONLY` | unset | `examples-aot-smoke.sh` | Slice e.g. `003` only ([#738](https://github.com/PurHur/php-compiler/issues/738), [#683](https://github.com/PurHur/php-compiler/issues/683)) |
| `DEPLOY_SMOKE_GATE` | `1` | `ci-local.sh` | `deploy-smoke.sh` 001/002 after `examples-aot-smoke` when LLVM ready ([#718](https://github.com/PurHur/php-compiler/issues/718), [#737](https://github.com/PurHur/php-compiler/issues/737)); 003 execute when `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `DEPLOY_SMOKE_003_EXECUTE` | `1` | `deploy-smoke.sh`, `ci-local.sh` | Default-on 003 deploy execute E2E ([#1530](https://github.com/PurHur/php-compiler/issues/1530)); also runs when `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `BOOTSTRAP_SELFHOST_PROBE_GATE` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `make bootstrap-selfhost-probe` on `compiler_minimal` ([#829](https://github.com/PurHur/php-compiler/issues/829)) |
| `BOOTSTRAP_SELFHOST_PROBE_UPDATE` | `0` | `ci_run_bootstrap_selfhost_probe` | Pass `--update-inventory` to probe (dev only) |
| `BOOTSTRAP_LOOP_PROBE_GATE` | `0` | `ci-local.sh` (LLVM tail, after selfhost-probe) | `./script/bootstrap-loop-probe.sh --dry-run` M4 ladder ([#1777](https://github.com/PurHur/php-compiler/issues/1777), [#1498](https://github.com/PurHur/php-compiler/issues/1498)) |
| `BOOTSTRAP_WAVE_CHECK` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `./script/bootstrap-wave-check.sh --fail-fast` after `@group aot-lint` |
| `CI_FAST_BOOTSTRAP` | `0` | `ci-fast.sh` | Optional llvm tail: bootstrap aot-lint + probe + wave-check when LLVM 9 present |
| `JIT_PREFLIGHT_GATE` | `0` | `ci-fast.sh` | Early MCJIT probe after `composer install` ([#728](https://github.com/PurHur/php-compiler/issues/728)) |
| `M2_SPINE_ISSUE_HYGIENE_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-m2-spine-issue-hygiene.php` — stale `m2-spine-unit` tickets ([#1819](https://github.com/PurHur/php-compiler/issues/1819), [#1808](https://github.com/PurHur/php-compiler/issues/1808)); set `0` for bulk spine PRs |
| `WAVE3_ROADMAP_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-wave3-roadmap-sync.php` ([#1802](https://github.com/PurHur/php-compiler/issues/1802), [#1814](https://github.com/PurHur/php-compiler/issues/1814)); set `0` for doc-only iteration |
| `EXAMPLES_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-examples-readme-sync.php` ([#1822](https://github.com/PurHur/php-compiler/issues/1822), [#1531](https://github.com/PurHur/php-compiler/issues/1531)); set `0` for doc-only iteration |
| `ROOT_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` ([#1832](https://github.com/PurHur/php-compiler/issues/1832), [#1525](https://github.com/PurHur/php-compiler/issues/1525)); set `0` for doc-only iteration |
| `SELFHOST_SPINE_COUNT_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-count-sync.php` ([#1834](https://github.com/PurHur/php-compiler/issues/1834)); set `0` for bulk spine PRs |

Ladder-only env vars (not in `ci-defaults.env`): `MINIWEBAPP_LINT_GATE` (default `1` in `web-smoke.sh`), `MINIWEBAPP_AOT_BISECT_GATE` (default `0` in `miniwebapp-gates.sh` — [#879](https://github.com/PurHur/php-compiler/issues/879)).

**003 link gate** (default on when LLVM ready — set `0` during execute-only iteration):

```bash
./script/ci-local.sh --filter 'ExamplesCompileTest::test003MiniWebAppBuildLinks'
MINIWEBAPP_AOT_LINK_GATE=0 ./script/ci-local.sh --filter ExamplesCompileTest   # skip 003 link (#754)
```

**003 AOT execute** (`MINIWEBAPP_AOT_EXECUTE_GATE=1` default; set `0` to skip during iteration):

```bash
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest
./script/ci-local.sh --filter test003MiniWebAppHomeRouteAotExecutes
./script/ci-local.sh --filter test003MiniWebAppExecutesWithCgiEnv
EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh
DEPLOY_SMOKE_GATE=0 ./script/ci-local.sh   # skip 001/002 deploy smoke (#737)
MINIWEBAPP_AOT_BISECT_GATE=1 ./script/miniwebapp-gates.sh
make north-star1-verify   # doctor --gates + ladder + ci-fast + AOT execute (#1845)
```

Set any gate to `0` to skip that stage during iteration (e.g. `MINIWEBAPP_SERVE_GATE=0 ./script/ci-fast.sh`, `MINIWEBAPP_AOT_EXECUTE_GATE=0 ./script/ci-local.sh`).

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

### AOT project preflight ([#746](https://github.com/PurHur/php-compiler/issues/746))

Fast north-star check before a full `ci-local.sh` llvm phase — `phpc build --project` plus CGI execute on `examples/003-MiniWebApp` (or optional project dir):

```bash
phpc doctor --aot-project-probe
# or: php script/aot-project-probe.php
```

Exits **0** with skip message when LLVM 9 is missing. Exits **0** when build succeeds and stdout contains the `app_name` needle (`MiniWebApp`). Exits **non-zero** on link failure or empty stdout (execute gap — was **#764**). Mirrors `MiniWebAppCgiEnv::queryRouteHome()` / stage 4b2 in `script/miniwebapp-gates.sh`.

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

- [#472](https://github.com/PurHur/php-compiler/issues/472) — MiniWebApp gate ladder umbrella
- [#664](https://github.com/PurHur/php-compiler/issues/664) — `MINIWEBAPP_WEB_SMOKE_GATE` in `ci-local`
- [#754](https://github.com/PurHur/php-compiler/issues/754) — `MINIWEBAPP_AOT_LINK_GATE`
- [#791](https://github.com/PurHur/php-compiler/issues/791) — `MINIWEBAPP_AOT_EXECUTE_GATE` split
- [#775](https://github.com/PurHur/php-compiler/issues/775) — `ci_run_miniwebapp_aot_execute` after aot-link
- [#737](https://github.com/PurHur/php-compiler/issues/737) — `DEPLOY_SMOKE_GATE` in `ci-local`
- [#436](https://github.com/PurHur/php-compiler/issues/436) — tiered CI (`ci-fast` vs `ci-local`)
- [#728](https://github.com/PurHur/php-compiler/issues/728) — optional `JIT_PREFLIGHT_GATE` on `ci-fast`
- [#497](https://github.com/PurHur/php-compiler/issues/497) — memory incident (closed)
- [#500](https://github.com/PurHur/php-compiler/issues/500) — VM RSS profiling
- [#501](https://github.com/PurHur/php-compiler/issues/501) — land CI caps (closed via PR)
- [#802](https://github.com/PurHur/php-compiler/issues/802) — stale closed-issue blocker strings in scripts/docs
- [#202](https://github.com/PurHur/php-compiler/issues/202) — Docker dev image docs + optional ghcr.io publish
