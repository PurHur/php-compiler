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

Defaults are exported from [`script/ci-defaults.env`](../script/ci-defaults.env) and read by `ci-local.sh`, `ci-fast.sh`, and helpers in [`script/ci-common.sh`](../script/ci-common.sh). For the progressive stage ladder (lint → serve → AOT link → execute), see **[miniwebapp-gates.md](miniwebapp-gates.md)** ([#472](https://github.com/PurHur/php-compiler/issues/472)); probe status with [`script/miniwebapp-gates.sh`](../script/miniwebapp-gates.sh), `make miniwebapp-gates`, or `phpc doctor --gates`. **Example web regression bundle** (gates + `ci-fast` MiniWebApp + AOT execute + optional AOT web-smoke; legacy name `north-star1-verify`): [`script/north-star1-verify.sh`](../script/north-star1-verify.sh) / `make north-star1-verify` ([#1845](https://github.com/PurHur/php-compiler/issues/1845), [#1044](https://github.com/PurHur/php-compiler/issues/1044) closed).

| Variable | Default | Script | Notes |
|----------|---------|--------|-------|
| `MINIWEBAPP_VM_CLI_GATE` | `1` | `ci-fast.sh` | PHPUnit `MiniWebApp*VmCli` matrix ([#597](https://github.com/PurHur/php-compiler/issues/597)) |
| `NESTED_RETURN_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `NestedReturn*` — nested `return <call>()` / late static binding VM ([#1888](https://github.com/PurHur/php-compiler/issues/1888), [#1885](https://github.com/PurHur/php-compiler/issues/1885)); set `0` to skip |
| `ATTRIBUTES_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `Attribute*` — PHP 8 attributes VM v1 ([#1904](https://github.com/PurHur/php-compiler/issues/1904), [#1354](https://github.com/PurHur/php-compiler/issues/1354)); set `0` to skip |
| `REHASH_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `array_rehash_string_keys` — HashTable mixed-key rehash ([#1956](https://github.com/PurHur/php-compiler/issues/1956), [#66](https://github.com/PurHur/php-compiler/issues/66)); set `0` to skip |
| `COALESCE_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `Coalesce*` — null coalescing `??` / `??=` VM ([#1960](https://github.com/PurHur/php-compiler/issues/1960), [#99](https://github.com/PurHur/php-compiler/issues/99)); set `0` to skip |
| `MINIWEBAPP_SERVE_GATE` | `1` | `ci-local.sh`, `ci-fast.sh` | `ServeTest` `@group miniwebapp` ([#641](https://github.com/PurHur/php-compiler/issues/641)) |
| `SESSIONS_WEB_SMOKE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` | `examples-web-smoke.sh --sessions-only` / 005 cookie flash curls ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) |
| `FILE_UPLOAD_WEB_SMOKE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` | `examples-web-smoke.sh --fileupload-only` / 006 multipart upload curls ([#2009](https://github.com/PurHur/php-compiler/issues/2009), [#1999](https://github.com/PurHur/php-compiler/issues/1999)) |
| `SESSIONS_WEB_AOT_LINK_GATE` | `1` | `ci-local.sh` (PHPUnit `@group aot-link`) | `ExamplesCompileTest::test005SessionsWebAotLink` — 005 native link ([#1946](https://github.com/PurHur/php-compiler/issues/1946)); set `0` to skip during iteration |
| `SESSIONS_WEB_AOT_SMOKE_GATE` | `0` | `ci-local.sh` (`ci_run_sessions_web_aot_execute`) | `SessionsWebAotExecuteTest` two-request execute ([#1891](https://github.com/PurHur/php-compiler/issues/1891), [#1923](https://github.com/PurHur/php-compiler/issues/1923)) |
| `MINIWEBAPP_WEB_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-web-smoke.sh --miniwebapp-only` ([#664](https://github.com/PurHur/php-compiler/issues/664)) |
| `MINIWEBAPP_WEB_SMOKE_AOT_GATE` | `1` | `ci-local.sh` | `ci_run_miniwebapp_web_smoke_aot` → `examples-web-smoke.sh --miniwebapp-only --aot` ([#1523](https://github.com/PurHur/php-compiler/issues/1523), [#833](https://github.com/PurHur/php-compiler/issues/833)) |
| `MINIWEBAPP_AOT_LINK_GATE` | `1` | `ci-local.sh` (PHPUnit `@group aot-link`) | `ExamplesCompileTest` 003 native link ([#754](https://github.com/PurHur/php-compiler/issues/754)) |
| `MINIWEBAPP_AOT_EXECUTE_GATE` | `1` | `ci-local.sh` after `@group aot-link` (`ci_run_miniwebapp_aot_execute`) | PHPUnit `@group miniwebapp-aot-execute` / `MiniWebAppAotExecuteTest` ([#747](https://github.com/PurHur/php-compiler/issues/747), [#791](https://github.com/PurHur/php-compiler/issues/791)) |
| `EXAMPLES_AOT_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-aot-smoke.sh` after LLVM phases ([#674](https://github.com/PurHur/php-compiler/issues/674)) |
| `EXAMPLES_AOT_SMOKE_ONLY` | unset | `examples-aot-smoke.sh` | Slice e.g. `003` only ([#738](https://github.com/PurHur/php-compiler/issues/738), [#683](https://github.com/PurHur/php-compiler/issues/683)) |
| `DEPLOY_SMOKE_GATE` | `1` | `ci-local.sh` | `deploy-smoke.sh` 001/002 after `examples-aot-smoke` when LLVM ready ([#718](https://github.com/PurHur/php-compiler/issues/718), [#737](https://github.com/PurHur/php-compiler/issues/737)); 003 execute when `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `DEPLOY_SMOKE_003_EXECUTE` | `1` | `deploy-smoke.sh`, `ci-local.sh` | Default-on 003 deploy execute E2E ([#1530](https://github.com/PurHur/php-compiler/issues/1530)); also runs when `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `SESSIONS_WEB_DEPLOY_SMOKE_GATE` | `0` | `deploy-smoke.sh`, `ci-local.sh` | Opt-in 005 deploy + `PHPC_DEPLOY_ROOT` session flash CGI ([#1893](https://github.com/PurHur/php-compiler/issues/1893)); VM curls stay on `SESSIONS_WEB_SMOKE_GATE=1` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) |
| `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE` | `0` | `deploy-smoke.sh`, `ci-local.sh` | Opt-in 006 deploy + `PHPC_DEPLOY_ROOT` multipart upload CGI ([#2028](https://github.com/PurHur/php-compiler/issues/2028)); VM curls stay on `FILE_UPLOAD_WEB_SMOKE_GATE=1` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| `DEPLOY_SMOKE_ALL` | `0` | `Makefile` `deploy-smoke` | When `1`, `make deploy-smoke` delegates to `deploy-smoke-all.sh` (same as `make deploy-smoke-all`) ([#2077](https://github.com/PurHur/php-compiler/issues/2077)) |
| `make deploy-smoke-all` | n/a | `script/deploy-smoke-all.sh` | Full deploy ladder 001–003 + opt-in 005/006; prints skip reasons when gates `0` — probe with `./phpc doctor --gates` ([#2077](https://github.com/PurHur/php-compiler/issues/2077)) |
| `BOOTSTRAP_SELFHOST_PROBE_GATE` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `make bootstrap-selfhost-probe` on `compiler_minimal` ([#829](https://github.com/PurHur/php-compiler/issues/829)) |
| `BOOTSTRAP_SELFHOST_PROBE_UPDATE` | `0` | `ci_run_bootstrap_selfhost_probe` | Pass `--update-inventory` to probe (dev only) |
| `BOOTSTRAP_LOOP_PROBE_GATE` | `0` | `ci-fast.sh` (LLVM opt-in), `ci-local.sh` (LLVM tail, after selfhost-probe) | `./script/bootstrap-loop-probe.sh --dry-run` M4 ladder ([#1777](https://github.com/PurHur/php-compiler/issues/1777), [#1498](https://github.com/PurHur/php-compiler/issues/1498), [#1929](https://github.com/PurHur/php-compiler/issues/1929)) |
| `BOOTSTRAP_WAVE_CHECK` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `./script/bootstrap-wave-check.sh --fail-fast` after `@group aot-lint` |
| `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE` | `0` | `ci-local.sh` (LLVM tail, after wave-check) | `bootstrap-selfhost-helloworld-probe.sh` with strict native emit ([#1526](https://github.com/PurHur/php-compiler/issues/1526)); set `1` when `emit_path=native` stable |
| `BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE` | `1` | `ci-local.sh` (LLVM tail, after selfhost-probe) | `bootstrap-selfhost-lib-spine-vm-smoke.sh` — M2 spine binary via `bin/vm.php -r` path ([#1846](https://github.com/PurHur/php-compiler/issues/1846), [#1867](https://github.com/PurHur/php-compiler/issues/1867)); set `0` for spine-only link PRs |
| `COMPILER_DRIVER_SMOKE_GATE` | `0` | `ci-local.sh` (LLVM tail, after lib-spine-vm-smoke) | `bootstrap-selfhost-compiler-driver-smoke-link.sh` — M3 `compiler_driver_smoke` native link + run ([#2136](https://github.com/PurHur/php-compiler/issues/2136)); set `1` to enforce; default-on follow-up [#2137](https://github.com/PurHur/php-compiler/issues/2137) |
| `CI_FAST_BOOTSTRAP` | `0` | `ci-fast.sh` | Optional llvm tail: bootstrap aot-lint + probe + wave-check when LLVM 9 present |
| `JIT_PREFLIGHT_GATE` | `0` | `ci-fast.sh` | Early MCJIT probe after `composer install` ([#728](https://github.com/PurHur/php-compiler/issues/728)) |
| `NORTH_STAR2_VERIFY_GATE` | `1` | `ci-fast.sh` | `./script/north-star2-verify.sh` presenter when script exists ([#1928](https://github.com/PurHur/php-compiler/issues/1928), [#2051](https://github.com/PurHur/php-compiler/issues/2051)); set `0` to opt out (no LLVM / doc-only iteration) |
| `BOOTSTRAP_TEST_SUBSET_GATE` | `0` | `ci-fast.sh` (after inventory checks) | `./script/bootstrap-test-subset.sh` — same as `phpc test --bootstrap` ([#2069](https://github.com/PurHur/php-compiler/issues/2069)); set `1` for NS2 fast path hook |
| `BOOTSTRAP_TEST_SUBSET_STRICT` | `0` | `ci_run_bootstrap_test_subset` | Pass `--strict` to subset script (M3 HelloWorld strict when LLVM ready); does not run in ci-fast by default |
| `phpc test --bootstrap` | n/a | `script/bootstrap-test-subset.sh` | Inventory `--check` + `SELFHOST_SPINE_COUNT_SYNC_GATE` spine sync (no LLVM link by default); `BOOTSTRAP_TEST_SUBSET_VM_SMOKE=1` for M2 VM spine smoke; `--strict` sets `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1` for M3 probe ([#1961](https://github.com/PurHur/php-compiler/issues/1961)) |
| `M2_SPINE_ISSUE_HYGIENE_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-m2-spine-issue-hygiene.php` — stale `m2-spine-unit` tickets ([#1819](https://github.com/PurHur/php-compiler/issues/1819), [#1808](https://github.com/PurHur/php-compiler/issues/1808)); set `0` for bulk spine PRs |
| `WAVE3_ROADMAP_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-wave3-roadmap-sync.php` ([#1802](https://github.com/PurHur/php-compiler/issues/1802), [#1814](https://github.com/PurHur/php-compiler/issues/1814)); set `0` for doc-only iteration |
| `EXAMPLES_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-examples-readme-sync.php` ([#1822](https://github.com/PurHur/php-compiler/issues/1822), [#1531](https://github.com/PurHur/php-compiler/issues/1531)); set `0` for doc-only iteration |
| `EXAMPLES_LADDER_DISCOVERY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-examples-ladder-discovery.php` — `examples/` dirs ↔ `ExamplesCompileTest` ([#1913](https://github.com/PurHur/php-compiler/issues/1913)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_005_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-005-row.php` — `005-SessionsWeb` run matrix + benchmark row vs `rebuild-examples.php` ([#1930](https://github.com/PurHur/php-compiler/issues/1930), [#1953](https://github.com/PurHur/php-compiler/issues/1953)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-006-row.php` — `006-FileUploadWeb` run matrix vs `FILE_UPLOAD_WEB_*` gate defaults ([#2018](https://github.com/PurHur/php-compiler/issues/2018), [#2052](https://github.com/PurHur/php-compiler/issues/2052)); set `0` for doc-only iteration |
| `CAPABILITIES_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-capabilities-fileuploadweb-sync.php` — `006` + `move_uploaded_file` / `$_FILES` rows in capability docs ([#2019](https://github.com/PurHur/php-compiler/issues/2019), [#2068](https://github.com/PurHur/php-compiler/issues/2068)); set `0` for matrix-only edits |
| `ROOT_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — 003/005/006/007 row parity ([#1832](https://github.com/PurHur/php-compiler/issues/1832), [#1525](https://github.com/PurHur/php-compiler/issues/1525), [#2017](https://github.com/PurHur/php-compiler/issues/2017), [#2094](https://github.com/PurHur/php-compiler/issues/2094)); set `0` for doc-only iteration |
| `DEVELOPMENT_STATUS_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-development-status-sync.php` — `docs/pages/development-status.md` vs examples/README 006 + NS2 M3 rows ([#2067](https://github.com/PurHur/php-compiler/issues/2067), [#2039](https://github.com/PurHur/php-compiler/issues/2039), default-on [#2083](https://github.com/PurHur/php-compiler/issues/2083)); set `0` for doc-only iteration |
| `ROOT_README_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — stale 006 gate phrases when defaults are on ([#2017](https://github.com/PurHur/php-compiler/issues/2017), [#2052](https://github.com/PurHur/php-compiler/issues/2052)); set `0` for doc-only iteration |
| `ROOT_README_007_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — stale 007 gate phrases when `THROWS_WEB_SMOKE_GATE` default is on ([#2094](https://github.com/PurHur/php-compiler/issues/2094)); set `0` for doc-only iteration |
| `SELFHOST_SPINE_COUNT_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-count-sync.php` (canonical `script/bootstrap-spine-count.php`, [#1834](https://github.com/PurHur/php-compiler/issues/1834), [#1872](https://github.com/PurHur/php-compiler/issues/1872)); set `0` for bulk spine PRs |
| `SELFHOST_SPINE_COVERAGE_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-coverage-sync.php` — inventory paths vs `compiler_lib_spine_smoke` ([#1955](https://github.com/PurHur/php-compiler/issues/1955), [#1945](https://github.com/PurHur/php-compiler/issues/1945)); set `0` for bulk spine path-only PRs |
| `SELFHOST_M4_GEN2_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-m4-gen2-sync.php` — M4 gen-2 `emit_path=*` docs vs `bootstrap-loop-gen1-link.sh` ([#2115](https://github.com/PurHur/php-compiler/issues/2115)); set `1` when editing M4 docs/scripts; flip default-on after [#2075](https://github.com/PurHur/php-compiler/issues/2075) |
| `BOOTSTRAP_M5_DOC_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-bootstrap-m5-doc-sync.php` — `docs/bootstrap-m5-fast-path.md` vs `script/m3-allowlist-snapshot.txt` ([#1984](https://github.com/PurHur/php-compiler/issues/1984)); set `0` for doc-only iteration |
| `BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `php script/bootstrap-vendor-inventory.php --check` — [`docs/bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md) vs live `vendor/` scan ([#2030](https://github.com/PurHur/php-compiler/issues/2030), default-on [#2040](https://github.com/PurHur/php-compiler/issues/2040)); set `0` for vendor-only iteration |
| `M3_ALLOWLIST_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-m3-allowlist-snapshot.php` ([#1905](https://github.com/PurHur/php-compiler/issues/1905)); set `0` for bulk symbol PRs |
| `INIT_MINIWEBAPP_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-miniwebapp-parity.sh` — `examples/003-MiniWebApp/` ↔ `templates/init-miniwebapp/` ([#2057](https://github.com/PurHur/php-compiler/issues/2057), [#695](https://github.com/PurHur/php-compiler/issues/695)); set `0` for doc-only iteration |
| `INIT_SESSIONSWEB_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-sessionsweb-parity.sh` — `examples/005-SessionsWeb/` ↔ `templates/init-sessionsweb/` ([#1902](https://github.com/PurHur/php-compiler/issues/1902)); set `0` for doc-only iteration |
| `INIT_FILEUPLOAD_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-fileupload-parity.sh` — `examples/006-FileUploadWeb/` ↔ `templates/init-fileupload/` ([#2004](https://github.com/PurHur/php-compiler/issues/2004), default-on [#2020](https://github.com/PurHur/php-compiler/issues/2020)); set `0` for doc-only iteration |
| `INIT_THROWSWEB_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-throwsweb-parity.sh` — `examples/007-ThrowsWeb/` ↔ `templates/init-throwsweb/` ([#2086](https://github.com/PurHur/php-compiler/issues/2086), default-on [#2127](https://github.com/PurHur/php-compiler/issues/2127)); set `0` for doc-only iteration |
| `APIJSON_INIT_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-apijson-parity.sh` — `examples/004-ApiJson/` ↔ `templates/init-apijson/` ([#2029](https://github.com/PurHur/php-compiler/issues/2029), default-on [#2037](https://github.com/PurHur/php-compiler/issues/2037)); set `0` for doc-only iteration |

Ladder-only env vars (not in `ci-defaults.env`): `MINIWEBAPP_LINT_GATE` (default `1` in `web-smoke.sh`), `MINIWEBAPP_AOT_BISECT_GATE` (default `0` in `miniwebapp-gates.sh` — [#879](https://github.com/PurHur/php-compiler/issues/879)).

**003 link gate** (default on when LLVM ready — set `0` during execute-only iteration):

```bash
./script/ci-local.sh --filter 'ExamplesCompileTest::test003MiniWebAppBuildLinks'
MINIWEBAPP_AOT_LINK_GATE=0 ./script/ci-local.sh --filter ExamplesCompileTest   # skip 003 link (#754)
```

**005 AOT link** (`SESSIONS_WEB_AOT_LINK_GATE=1` default — [#1946](https://github.com/PurHur/php-compiler/issues/1946)):

```bash
./script/ci-local.sh --filter test005SessionsWebAotLink
SESSIONS_WEB_AOT_LINK_GATE=0 ./script/ci-local.sh --filter ExamplesCompileTest   # skip 005 link
```

## 005-SessionsWeb gates ([#1881](https://github.com/PurHur/php-compiler/issues/1881), [#1969](https://github.com/PurHur/php-compiler/issues/1969))

Progressive ladder (VM → AOT link → AOT execute → deploy CGI). Probe with `phpc doctor --gates` or grep `005-SessionsWeb` in the output.

| Stage | Variable | Default | When `=1` |
|-------|----------|---------|-----------|
| VM session flash | `SESSIONS_WEB_SMOKE_GATE` | `1` | `make examples-sessions-smoke` / `ci-fast` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) |
| AOT link | `SESSIONS_WEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test005SessionsWebAotLink` ([#1946](https://github.com/PurHur/php-compiler/issues/1946)) |
| AOT execute | `SESSIONS_WEB_AOT_SMOKE_GATE` | `0` | `SessionsWebAotExecuteTest` or `EXAMPLES_AOT_SMOKE_ONLY=005 ./script/examples-aot-smoke.sh` ([#1891](https://github.com/PurHur/php-compiler/issues/1891) ✅) |
| Deploy CGI | `SESSIONS_WEB_DEPLOY_SMOKE_GATE` | `0` | `SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all` ([#1893](https://github.com/PurHur/php-compiler/issues/1893), [#1962](https://github.com/PurHur/php-compiler/issues/1962), [#2077](https://github.com/PurHur/php-compiler/issues/2077)) |

Stages 2–4 require LLVM 9. Execute landed ([#1891](https://github.com/PurHur/php-compiler/issues/1891)); default-on for execute/deploy gates tracked in [#1923](https://github.com/PurHur/php-compiler/issues/1923) / [#1967](https://github.com/PurHur/php-compiler/issues/1967).

```bash
./phpc doctor --gates | grep -E 'SESSIONS_WEB|005-SessionsWeb'
SESSIONS_WEB_AOT_SMOKE_GATE=1 ./script/ci-local.sh --filter SessionsWebAotExecuteTest
SESSIONS_WEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=005 ./script/examples-aot-smoke.sh
SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 005
```

## 006-FileUploadWeb gates ([#1999](https://github.com/PurHur/php-compiler/issues/1999), [#2009](https://github.com/PurHur/php-compiler/issues/2009))

Progressive ladder (VM multipart → AOT link → AOT execute → deploy CGI). VM smoke default-on ([#2009](https://github.com/PurHur/php-compiler/issues/2009)); AOT link default-on ([#2011](https://github.com/PurHur/php-compiler/issues/2011)); AOT execute default-on ([#2012](https://github.com/PurHur/php-compiler/issues/2012)). Copy-paste ladder: `./phpc doctor --gates` (**#2010**).

| Stage | Variable | Default | When enabled |
|-------|----------|---------|--------------|
| VM multipart | `FILE_UPLOAD_WEB_SMOKE_GATE` | `1` | `ci-fast` / `ci-local` / `examples-web-smoke.sh --fileupload-only` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| AOT link | `FILE_UPLOAD_WEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test006FileUploadWebAotLink` ([#2011](https://github.com/PurHur/php-compiler/issues/2011)); set `0` to skip during iteration |
| AOT execute | `FILE_UPLOAD_WEB_AOT_SMOKE_GATE` | `1` | `FileUploadWebAotExecuteTest` or `EXAMPLES_AOT_SMOKE_ONLY=006 ./script/examples-aot-smoke.sh` ([#2012](https://github.com/PurHur/php-compiler/issues/2012)) |
| Deploy CGI | `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE` | `0` | `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all` ([#2044](https://github.com/PurHur/php-compiler/issues/2044), [#2077](https://github.com/PurHur/php-compiler/issues/2077)); `make examples-fileupload-deploy-smoke` (006 only); `ci-local` opt-in ([#2038](https://github.com/PurHur/php-compiler/issues/2038), [#2042](https://github.com/PurHur/php-compiler/issues/2042)) |

```bash
FILE_UPLOAD_WEB_SMOKE_GATE=0 ./script/ci-fast.sh   # skip 006 multipart curls
FILE_UPLOAD_WEB_AOT_LINK_GATE=0 ./script/ci-local.sh   # skip 006 AOT link (@group aot-link)
./script/ci-local.sh --filter test006FileUploadWebAotLink
./script/ci-local.sh --filter FileUploadWebAotExecuteTest
EXAMPLES_AOT_SMOKE_ONLY=006 ./script/examples-aot-smoke.sh
FILE_UPLOAD_WEB_AOT_SMOKE_GATE=0 ./script/ci-local.sh   # skip 006 AOT execute during iteration
make examples-fileupload-deploy-smoke
FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 006
```

## 007-ThrowsWeb gates ([#2076](https://github.com/PurHur/php-compiler/issues/2076), [#2093](https://github.com/PurHur/php-compiler/issues/2093))

Progressive ladder (VM throw/catch → AOT link → AOT execute). VM smoke opt-in ([#2093](https://github.com/PurHur/php-compiler/issues/2093)); AOT gates opt-in until **#195** / **#57** / **#2101** land. Copy-paste ladder: `./phpc doctor --gates` (**#2102**).

| Stage | Variable | Default | When enabled |
|-------|----------|---------|--------------|
| VM throw/catch | `THROWS_WEB_SMOKE_GATE` | `0` | `THROWS_WEB_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only` ([#2093](https://github.com/PurHur/php-compiler/issues/2093)) |
| AOT link | `THROWSWEB_AOT_LINK_GATE` | `0` | `./script/ci-local.sh --filter ThrowsWebAotLinkTest` ([#2101](https://github.com/PurHur/php-compiler/issues/2101)) |
| AOT execute | `THROWSWEB_AOT_SMOKE_GATE` | `0` | `ThrowsWebAotExecuteTest` or `EXAMPLES_AOT_SMOKE_ONLY=007 ./script/examples-aot-smoke.sh` ([#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2104](https://github.com/PurHur/php-compiler/issues/2104)) |

```bash
./phpc doctor --gates | grep -E 'THROWS|007-ThrowsWeb'
THROWS_WEB_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only
THROWSWEB_AOT_LINK_GATE=1 THROWSWEB_AOT_SMOKE_GATE=1 ./script/ci-local.sh --filter ThrowsWebAot
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
./script/ci-fast.sh   # NS2 presenter default-on (#1928, #2051); NORTH_STAR2_VERIFY_GATE=0 to skip
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

### Self-host presenter on fast CI ([#1928](https://github.com/PurHur/php-compiler/issues/1928), [#2051](https://github.com/PurHur/php-compiler/issues/2051))

Self-host iteration runs the NS2 presenter bundle from `ci-fast` without a full `ci-local.sh` LLVM tail. Default **on** in `script/ci-defaults.env` ([#2051](https://github.com/PurHur/php-compiler/issues/2051)):

```bash
./script/ci-fast.sh
# Opt-out (doc-only / no presenter):
NORTH_STAR2_VERIFY_GATE=0 ./script/ci-fast.sh
# Docker:
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev ./script/ci-fast.sh
```

When the script is missing, CI prints a skip message and exits **0**. With LLVM 9 present, runs `./script/north-star2-verify.sh` (full tail); without LLVM, passes `--skip-llvm-tail`.

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
