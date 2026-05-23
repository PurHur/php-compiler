# MiniWebApp AOT unskip matrix

Ordered checklist for [`examples/003-MiniWebApp/`](../examples/003-MiniWebApp/) AOT execute and HTTP gates. Umbrella tracker: [#676](https://github.com/PurHur/php-compiler/issues/676). Do **not** reorder — flip gates only after the prior step is green.

| Step | Issue | Gate / artifact | Status | Notes |
|------|-------|-----------------|--------|-------|
| 4b | [#754](https://github.com/PurHur/php-compiler/issues/754) | `MINIWEBAPP_AOT_LINK_GATE=1` → `ExamplesCompileTest::test003MiniWebAppBuildLinks` | ✅ | Native link default-on in `ci-defaults.env` / `ci-local.sh` |
| 4b2 execute | [#747](https://github.com/PurHur/php-compiler/issues/747) | `MINIWEBAPP_AOT_EXECUTE_GATE=1` → `MiniWebAppAotExecuteTest` | 🚧 | PATH_INFO via `$_SERVER` (#1082); contact POST + shutdown `free()` tracked (#1084) |
| 4c | [#738](https://github.com/PurHur/php-compiler/issues/738) | `EXAMPLES_AOT_SMOKE_ONLY=003` → `examples-aot-smoke.sh` | ✅ | Home CLI probe passes when stdout contains `MiniWebApp`; fails (not skip) when gate on and empty |
| 3 AOT | [#833](https://github.com/PurHur/php-compiler/issues/833) | `examples-web-smoke.sh --aot` 003 | 🚧 | Home + hello PATH_INFO + contact POST when binary ready; opt-in `MINIWEBAPP_WEB_SMOKE_AOT_GATE=1` |
| 4 PHPUnit HTTP | [#478](https://github.com/PurHur/php-compiler/issues/478) | `MiniWebAppServeAotTest` | 🚧 | `MINIWEBAPP_SERVE_AOT_GATE=1` — 5/6 green; PATH_INFO hello skipped until 4b2 stable (#1067) |
| 4 assets | [#610](https://github.com/PurHur/php-compiler/issues/610) | `GET /assets/style.css` | ✅ | Static CSS via AOT serve (#1067) |
| 4d execute | [#745](https://github.com/PurHur/php-compiler/issues/745) | `deploy-smoke.sh --example 003` execute | ✅ | `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1`; home + hello + contact via `MiniWebAppCgiEnv` (#1065) |

**Legend:** ✅ green / default-on · 🚧 wired, blocked on execute parity · ⬜ deferred (explicit gate off)

## How to flip

Each row becomes default-on in `script/ci-defaults.env` and `ci-local.sh` only after its probe is green on `master`. Use these commands to verify before flipping.

| Step | Flip when | Enable in CI | Verify |
|------|-----------|--------------|--------|
| 4b link | `test003MiniWebAppBuildLinks` passes | `MINIWEBAPP_AOT_LINK_GATE=1` (✅ default) | `./script/ci-local.sh --filter test003MiniWebAppBuildLinks` |
| 4b2 execute | `MiniWebAppAotExecuteTest` 4/4 | `MINIWEBAPP_AOT_EXECUTE_GATE=1` (✅ default) | `MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest` |
| 4c AOT smoke | `EXAMPLES_AOT_SMOKE_ONLY=003` exits 0 with `003-MiniWebApp: ok` | `EXAMPLES_AOT_SMOKE_GATE=1` (✅ default) | `EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh` |
| 3 AOT HTTP | `--aot --miniwebapp-only` home + hello + contact green | `MINIWEBAPP_WEB_SMOKE_AOT_GATE=1` (opt-in until 4b2 stable) | `./script/examples-web-smoke-prebuild.sh && ./script/examples-web-smoke.sh --aot --miniwebapp-only` |
| 4 PHPUnit | `MiniWebAppServeAotTest` 6/6 green | `MINIWEBAPP_SERVE_AOT_GATE=1` | `MINIWEBAPP_SERVE_AOT_GATE=1 ./script/ci-local.sh --filter MiniWebAppServeAot` |
| 4 assets | `/assets/style.css` AOT serve green | same as #610 | included in `MiniWebAppServeAotTest` |
| 4d deploy | `deploy-smoke.sh --example 003` execute green | `DEPLOY_SMOKE_003_EXECUTE=1` or execute gate (✅) | `DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh --example 003` |

**Iteration opt-out:** set the gate env var to `0` (e.g. `MINIWEBAPP_AOT_EXECUTE_GATE=0 ./script/ci-local.sh`) without changing defaults in `ci-defaults.env`.

## Quick probes

```bash
# Link (754)
./script/ci-local.sh --filter test003MiniWebAppBuildLinks

# CLI execute (747) — default on
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest

# CLI smoke slice (738)
EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh

# HTTP AOT smoke slice (833)
./script/examples-web-smoke-prebuild.sh
./script/examples-web-smoke.sh --aot --miniwebapp-only

# Serve AOT PHPUnit (#478, #610)
MINIWEBAPP_SERVE_AOT_GATE=1 ./script/ci-local.sh --filter MiniWebAppServeAot

# Deploy layout (745 partial)
DEPLOY_SMOKE_003_LAYOUT=1 ./script/deploy-smoke.sh --example 003

# Deploy execute E2E (745)
DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh --example 003
```

When [#747](https://github.com/PurHur/php-compiler/issues/747) CLI execute is 4/4 stable, enable `MINIWEBAPP_WEB_SMOKE_AOT_GATE=1` in `ci-defaults.env`, unskip PATH_INFO in `MiniWebAppServeAotTest`, then close [#676](https://github.com/PurHur/php-compiler/issues/676).

See also: [miniwebapp-gates.md](miniwebapp-gates.md), [#472](https://github.com/PurHur/php-compiler/issues/472) gate ladder.
