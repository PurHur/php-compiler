# MiniWebApp CI gate ladder

Progressive stages for [`examples/003-MiniWebApp/`](../examples/003-MiniWebApp/) so contributors know which checks are **default-on** in full CI vs **opt-in** during iteration. Umbrella tracker: [#472](https://github.com/PurHur/php-compiler/issues/472).

**Probe current status:**

```bash
make miniwebapp-gates
# or: ./script/miniwebapp-gates.sh
# or: ./phpc doctor --gates
```

The script prints `[x]` / `[ ]` per stage and suggests the next blocker. Use `--no-lint` to skip the `phpc lint --all` probe when iterating on later stages.

## Stage ladder

Matches [`script/miniwebapp-gates.sh`](../script/miniwebapp-gates.sh) output order (lint → serve → web-smoke → AOT link → execute → deploy).

| Stage | Check | Env var / command | Default on `master` | CI hook | Tracking |
|-------|--------|-------------------|---------------------|---------|----------|
| 0 | Skeleton opt-out | `MINIWEBAPP_LINT_GATE=0` | opt-out only | `web-smoke.sh` continues on lint failure | [#621](https://github.com/PurHur/php-compiler/issues/621) |
| 1 | Lint green | `MINIWEBAPP_LINT_GATE=1` (default) | ✅ | `make web-smoke` fails on lint regression | [#539](https://github.com/PurHur/php-compiler/issues/539), [#621](https://github.com/PurHur/php-compiler/issues/621) |
| 1b | VM CLI route matrix | `MINIWEBAPP_VM_CLI_GATE=1` (default) | ✅ | `ci-fast.sh` → `MiniWebApp*VmCli` | [#597](https://github.com/PurHur/php-compiler/issues/597) |
| 2 | PHPUnit serve | `MINIWEBAPP_SERVE_GATE=1` (default) | ✅ | `ci-local.sh`, `ci-fast.sh` → `ServeTest` `@group miniwebapp` | [#641](https://github.com/PurHur/php-compiler/issues/641), [#470](https://github.com/PurHur/php-compiler/issues/470) |
| 3 | Examples web-smoke | wired in `examples-web-smoke.sh` | ✅ | `make examples-web-smoke` includes 003 curls | [#461](https://github.com/PurHur/php-compiler/issues/461) |
| 3b | ci-local shell smoke | `MINIWEBAPP_WEB_SMOKE_GATE=1` (default) | ✅ | `ci-local.sh` → `examples-web-smoke.sh --miniwebapp-only` | [#664](https://github.com/PurHur/php-compiler/issues/664), [#633](https://github.com/PurHur/php-compiler/issues/633) |
| 4a | AOT dry-run | `phpc build --project examples/003-MiniWebApp --dry-run` | probe (LLVM 9) | `@group aot-lint` in `ci-local.sh` | [#624](https://github.com/PurHur/php-compiler/issues/624), [#675](https://github.com/PurHur/php-compiler/issues/675) |
| 4b | AOT link | `MINIWEBAPP_AOT_LINK_GATE=1` (default) | ✅ | `ci-local.sh` → `ExamplesCompileTest` `@group aot-link` / `@group miniwebapp` | [#754](https://github.com/PurHur/php-compiler/issues/754), [#454](https://github.com/PurHur/php-compiler/issues/454) |
| 4b2 | AOT CLI execute byte probe | `phpc build --project` + `MiniWebAppCgiEnv` + `.phpc/bin/app` | probe (LLVM 9) | `miniwebapp-gates.sh` stage 4b2 (#773) | [#773](https://github.com/PurHur/php-compiler/issues/773), [#764](https://github.com/PurHur/php-compiler/issues/764) |
| 4b2 bisect | AOT bisect ladder | `MINIWEBAPP_AOT_BISECT_GATE=1` | opt-in (default off) | `miniwebapp-gates.sh` probe; `ci-local.sh --group miniwebapp-bisect` | [#879](https://github.com/PurHur/php-compiler/issues/879), [#764](https://github.com/PurHur/php-compiler/issues/764) |
| 4b2 execute | AOT CLI execute (PHPUnit) | `MINIWEBAPP_AOT_EXECUTE_GATE=1` | opt-in (default off); green when enabled ([#747](https://github.com/PurHur/php-compiler/issues/747) ✅) | `ci-local.sh` → `ci_run_miniwebapp_aot_execute` after `@group aot-link` | [#791](https://github.com/PurHur/php-compiler/issues/791), [#775](https://github.com/PurHur/php-compiler/issues/775) |
| 4c | AOT shell smoke (003 slice) | `EXAMPLES_AOT_SMOKE_GATE=1`, `EXAMPLES_AOT_SMOKE_ONLY=003` | probe (003 enabled; `MINIWEBAPP_AOT_EXECUTE_GATE=1` to hard-fail) | `ci-local.sh` → `examples-aot-smoke.sh` | [#738](https://github.com/PurHur/php-compiler/issues/738), [#683](https://github.com/PurHur/php-compiler/issues/683) |
| 4d | Deploy smoke | `DEPLOY_SMOKE_GATE=1` (default) | 001/002 only | `ci-local.sh` → `deploy-smoke.sh`; 003 not enabled until [#612](https://github.com/PurHur/php-compiler/issues/612) | [#718](https://github.com/PurHur/php-compiler/issues/718) |

Defaults for `MINIWEBAPP_SERVE_GATE`, `MINIWEBAPP_WEB_SMOKE_GATE`, `MINIWEBAPP_AOT_LINK_GATE`, `MINIWEBAPP_AOT_EXECUTE_GATE`, `EXAMPLES_AOT_SMOKE_GATE`, and `DEPLOY_SMOKE_GATE` are exported from [`script/ci-defaults.env`](../script/ci-defaults.env). Ladder-only vars (`MINIWEBAPP_LINT_GATE`, `MINIWEBAPP_AOT_BISECT_GATE`) are read directly by their scripts — see [local-ci-matrix.md § MiniWebApp gates](local-ci-matrix.md#miniwebapp-gates) for the full env table.

## Quick commands

```bash
# Stages 0–1: lint + VM smoke
make web-smoke
MINIWEBAPP_LINT_GATE=0 make web-smoke          # stage 0 skeleton

# Stage 1b
MINIWEBAPP_VM_CLI_GATE=1 ./script/ci-fast.sh

# Stage 2: skip during iteration
MINIWEBAPP_SERVE_GATE=0 ./script/ci-local.sh --filter ServeTest

# Stage 3
make examples-web-smoke

# Stage 3b: skip shell PATH_INFO curls
MINIWEBAPP_WEB_SMOKE_GATE=0 ./script/ci-local.sh

# Stage 4a
./phpc build --project examples/003-MiniWebApp --dry-run

# Stage 4b
./script/ci-local.sh --filter test003MiniWebAppBuildLinks
MINIWEBAPP_AOT_LINK_GATE=0 ./script/ci-local.sh --filter ExamplesCompileTest   # skip link during iteration (#754)

# Stage 4b2 execute (opt-in while #764 open)
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest

# Stage 4b2 byte probe (manual)
./phpc build --project examples/003-MiniWebApp
eval "$(./script/miniwebapp-cgi-env.php --export shellQueryRouteHome)"
eval "$(./script/miniwebapp-cgi-env.php --export aotFrontController)"
examples/003-MiniWebApp/.phpc/bin/app | wc -c

# Stage 4c
EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh

# Stage 4d
make deploy-smoke
DEPLOY_SMOKE_GATE=0 ./script/ci-local.sh       # skip 001/002 deploy smoke

# Stage 4b2 bisect (opt-in)
MINIWEBAPP_AOT_BISECT_GATE=1 ./script/miniwebapp-gates.sh
./script/miniwebapp-aot-bisect.sh --list
```

Set any gate to `0` to skip that stage during fast iteration. Full CI: `./script/ci-local.sh` or `make test`.

## Related docs

- [examples/003-MiniWebApp/README.md](../examples/003-MiniWebApp/README.md) — routes, parity checks, example-local status table
- [local-ci-matrix.md](local-ci-matrix.md) — Docker/host CI entry points and env defaults
- [deploy-web-aot.md](deploy-web-aot.md) — AOT deploy quickstart
