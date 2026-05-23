# MiniWebApp AOT unskip matrix

Ordered checklist for [`examples/003-MiniWebApp/`](../examples/003-MiniWebApp/) AOT execute and HTTP gates. Umbrella tracker: [#676](https://github.com/PurHur/php-compiler/issues/676). Do **not** reorder — flip gates only after the prior step is green.

| Step | Issue | Gate / artifact | Status | Notes |
|------|-------|-----------------|--------|-------|
| 4b | [#754](https://github.com/PurHur/php-compiler/issues/754) | `MINIWEBAPP_AOT_LINK_GATE=1` → `ExamplesCompileTest::test003MiniWebAppBuildLinks` | ✅ | Native link default-on in `ci-defaults.env` / `ci-local.sh` |
| 4b2 execute | [#747](https://github.com/PurHur/php-compiler/issues/747) | `MINIWEBAPP_AOT_EXECUTE_GATE=1` → `MiniWebAppAotExecuteTest` | 🚧 | PATH_INFO via `$_SERVER` (#1082); contact POST + shutdown `free()` tracked (#1084) |
| 4c | [#738](https://github.com/PurHur/php-compiler/issues/738) | `EXAMPLES_AOT_SMOKE_ONLY=003` → `examples-aot-smoke.sh` | ✅ | Home CLI probe passes when stdout contains `MiniWebApp`; fails (not skip) when gate on and empty |
| 3 AOT | [#833](https://github.com/PurHur/php-compiler/issues/833) | `examples-web-smoke.sh --aot` 003 | 🚧 | Home + hello PATH_INFO + contact POST when binary ready; opt-in `MINIWEBAPP_WEB_SMOKE_AOT_GATE=1` |
| 4 PHPUnit HTTP | [#478](https://github.com/PurHur/php-compiler/issues/478) | `MiniWebAppServeAotTest` | 🚧 | `MINIWEBAPP_SERVE_AOT_GATE=1` or execute gate; 5/6 green; PATH_INFO hello skipped until 4b2 stable (#1067) |
| 4 assets | [#610](https://github.com/PurHur/php-compiler/issues/610) | `GET /assets/style.css` | ✅ | Static CSS via AOT serve (#1067) |
| 4d execute | [#745](https://github.com/PurHur/php-compiler/issues/745) | `deploy-smoke.sh --example 003` execute | ✅ | `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1`; home + hello + contact via `MiniWebAppCgiEnv` (#1065) |

**Legend:** ✅ green / default-on · 🚧 wired, blocked on execute parity · ⬜ deferred (explicit gate off)

## Quick probes

```bash
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest
MINIWEBAPP_SERVE_AOT_GATE=1 ./script/ci-local.sh --filter MiniWebAppServeAot
DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh --example 003
```

See also: [miniwebapp-gates.md](miniwebapp-gates.md), [#472](https://github.com/PurHur/php-compiler/issues/472) gate ladder.
