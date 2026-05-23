# MiniWebApp AOT unskip matrix

Ordered checklist for [`examples/003-MiniWebApp/`](../examples/003-MiniWebApp/) AOT execute and HTTP gates. Umbrella tracker: [#676](https://github.com/PurHur/php-compiler/issues/676). Do **not** reorder — flip gates only after the prior step is green.

| Step | Issue | Gate / artifact | Status | Notes |
|------|-------|-----------------|--------|-------|
| 4b | [#754](https://github.com/PurHur/php-compiler/issues/754) | `MINIWEBAPP_AOT_LINK_GATE=1` → `ExamplesCompileTest::test003MiniWebAppBuildLinks` | ✅ | Native link default-on in `ci-local.sh` |
| 4b2 execute | [#747](https://github.com/PurHur/php-compiler/issues/747) | `MINIWEBAPP_AOT_EXECUTE_GATE=1` → `MiniWebAppAotExecuteTest` | 🚧 | CLI wired; home HTML partial, PATH_INFO hello segfault, contact invalid |
| 4c | [#738](https://github.com/PurHur/php-compiler/issues/738) | `EXAMPLES_AOT_SMOKE_ONLY=003` → `examples-aot-smoke.sh` | 🚧 | Slice + `#764` byte probe; skips until stdout contains `MiniWebApp` |
| 3 AOT | [#833](https://github.com/PurHur/php-compiler/issues/833) | `examples-web-smoke.sh --aot` 003 | 🚧 | Home + hello PATH_INFO curls when binary ready; skips on empty stdout |
| 4 PHPUnit HTTP | [#478](https://github.com/PurHur/php-compiler/issues/478) | `ServeAotTest::testServes003MiniWebApp*` | ⬜ | No shipped 003 `phpc serve --aot` tests yet |
| 4 assets | [#610](https://github.com/PurHur/php-compiler/issues/610) | `ServeAotTest` `/assets/style.css` | ⬜ | Static assets from 003 docroot via AOT serve |
| 4d execute | [#745](https://github.com/PurHur/php-compiler/issues/745) | `deploy-smoke.sh --example 003` execute | ✅ | `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1`; home + hello + contact via `MiniWebAppCgiEnv` |

**Legend:** ✅ green / default-on · 🚧 wired, blocked on execute parity · ⬜ not started

## Quick probes

```bash
# Link (754)
./script/ci-local.sh --filter test003MiniWebAppBuildLinks

# CLI execute (747) — opt-in
MINIWEBAPP_AOT_EXECUTE_GATE=1 ./script/ci-local.sh --filter MiniWebAppAotExecuteTest

# CLI smoke slice (738)
EXAMPLES_AOT_SMOKE_ONLY=003 ./script/examples-aot-smoke.sh

# HTTP AOT smoke slice (833)
./script/examples-web-smoke-prebuild.sh
./script/examples-web-smoke.sh --aot --miniwebapp-only

# Deploy layout (745 partial)
DEPLOY_SMOKE_003_LAYOUT=1 ./script/deploy-smoke.sh --example 003

# Deploy execute E2E (745)
DEPLOY_SMOKE_003_EXECUTE=1 ./script/deploy-smoke.sh --example 003
```

When [#747](https://github.com/PurHur/php-compiler/issues/747) and [#833](https://github.com/PurHur/php-compiler/issues/833) are green, enable [#478](https://github.com/PurHur/php-compiler/issues/478) / [#610](https://github.com/PurHur/php-compiler/issues/610) PHPUnit HTTP gates, then [#745](https://github.com/PurHur/php-compiler/issues/745) deploy execute in `ci-local.sh`.

See also: [miniwebapp-gates.md](miniwebapp-gates.md), [#472](https://github.com/PurHur/php-compiler/issues/472) gate ladder.
