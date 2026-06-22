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
| User release readiness (quick) | `./script/release-readiness.sh --json` | `./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --json'` |
| User release readiness (full) | `./script/release-readiness.sh --full --json` | `./script/docker-exec.sh -- bash -lc './script/release-readiness.sh --full --json'` |

### Release readiness (`release-readiness.sh`, #8737)

Daily v1.1.0 release review presenter — aggregates user-facing gates without the ~1h `north-star5-verify --strict` ladder.

| Mode | Gates | Target time |
|------|-------|-------------|
| Quick (default) | `bootstrap-inventory.php --check`, `check-selfhost-spine-coverage-sync.php`, `north-star5-verify-fast`, VM driver probe, `check-root-readme-sync.php` | <5 min Docker |
| Quick + CI doc slice | Set `RELEASE_READINESS_CI_FAST=1` — adds wave3/examples/development-status/spine-count/capability-matrix sync checks | ~minutes |
| Full (`--full`) | Quick + `capability-matrix.php --check`, `examples-aot-smoke.sh`, `examples-web-smoke.sh`, `CHANGELOG.md` v1.1.0 stub | LLVM + HTTP when available |

Machine output: `./script/release-readiness.sh [--full] --json` → `{"user_release_ready":"yes"|"no","mode":"quick"|"full","gates":[...]}`.

**bootstrap-inventory gate (#10531):** `release-readiness.sh` requires `vendor/` (runs `composer install` if missing) and treats `--check` as green only when stdout contains `OK N/N`. A bare `php script/bootstrap-inventory.php --check` without `vendor/` exits **1** — do not rely on a silent skip. File-list drift: `php script/bootstrap-inventory.php`. Optional construct-flag refresh after a self-host probe only: `docs/bootstrap-inventory-live-probe.md` (not required for new vm.php-path files; see #10368).

Parent: [#8739](https://github.com/PurHur/php-compiler/issues/8739) · [#78](https://github.com/PurHur/php-compiler/issues/78).

## Runforge / harness verification

On **Runforge harness** hosts (and similar agent sandboxes), `docker run -v "$(pwd):/compiler"` often mounts an **empty** tree at `/compiler`, so JIT/AOT and `vendor/` lookups fail ([#245](https://github.com/PurHur/php-compiler/issues/245), workspace rule `local-ci-only`). Use the tar-copy wrappers below instead of raw bind-mount `docker run`.

| Use case | Command |
|----------|---------|
| Full CI gate | `make test-harness` or `./script/docker-ci-local.sh` |
| Targeted PHPUnit | `./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && vendor/bin/phpunit …'` |
| Never on harness | Raw `docker run -v "$(pwd):/compiler"` (empty tree) |

**Harness Docker resource limits** ([#2249](https://github.com/PurHur/php-compiler/issues/2249)): Runforge/agent-harness hosts export `HARNESS_DOCKER_RUN_OPTS` (e.g. `--memory=8g --cpus=2`). All wrapper scripts source `script/ci-docker-run.sh`, which passes those flags (or `PHP_COMPILER_DOCKER_RUN_OPTS`) on every `docker run` / `docker create`, together with the repo memory cap from `ci-defaults.env`.

| Variable / entrypoint | Behavior |
|-----------------------|----------|
| `HARNESS_DOCKER_RUN_OPTS` | Injected by harness; preferred on Runforge |
| `PHP_COMPILER_DOCKER_RUN_OPTS` | Explicit override (wins over harness var when set) |
| `PHP_COMPILER_REQUIRE_DOCKER_RUN_OPTS=1` | Fail fast if neither var is set (`make test-harness`, `make test-docker-exec`) |
| `ci_docker_harness_context` | Detects `HARNESS_HOST` / `HARNESS_PORT` / `HARNESS_DATA_DIR` and prints a high-signal warning when opts are missing |

Do **not** call raw `docker run` in harness docs or issues — use `./script/docker-exec.sh`, `./script/docker-ci-local.sh`, or `make test-harness` so limits and tar-fallback stay consistent ([#2245](https://github.com/PurHur/php-compiler/issues/2245)).

**Docker preflight** ([#2246](https://github.com/PurHur/php-compiler/issues/2246)): harness entrypoints (`make test-harness`, `./script/docker-ci-local.sh`, `./script/docker-exec.sh`, `./script/ci-docker-safe.sh`) call `script/ci-docker-preflight.sh` before any container work:

| Check | Behavior |
|-------|----------|
| `docker info` | Fail fast with an actionable error when the daemon is unreachable |
| Single CI container | `flock` on `.php-compiler-ci.lock` (workspace); blocks a second concurrent wrapper run |
| Opt-out | `PHP_COMPILER_CI_SINGLE_CONTAINER=0` skips the lock; `PHP_COMPILER_CI_VERBOSE=1` prints one-line OK |

Do **not** run several full CI containers in parallel — each `vm.php` child can grow large; wait for the other run or stop its container.

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

**Local equivalent (Docker)** — canonical today (harness-safe; tar-copies when bind-mount is empty):

```bash
./script/docker-exec.sh -- bash -lc \
  'make bootstrap-selfhost-probe && ./script/bootstrap-selfhost-link.sh && ./script/bootstrap-wave-check.sh'
```

**Local equivalent (host, no Docker):** `composer install`, `./script/install-llvm9.sh`, then the same three commands.

**Former GHA llvm job** (full VM + JIT + AOT gate): reproduce with `./script/ci-local.sh` or `make test-docker` (see Entry points above). Fast iteration without LLVM link: `./script/ci-fast.sh` or `make test-fast`.

## LLVM 14 migration (opt-in, [#174](https://github.com/PurHur/php-compiler/issues/174))

Default CI and Docker still use **LLVM 9** (`script/install-llvm9.sh`, `/opt/llvm9`). LLVM 14 is an **opt-in** migration path until PHPLLVM FFI and linker paths are proven on `master`.

| Step | Command / variable | Notes |
|------|-------------------|-------|
| Install LLVM 14 tree | `./script/install-llvm14.sh` | Installs under repo `.llvm14/` (Debian bookworm packages) |
| Override install dir | `PHP_COMPILER_LLVM14_INSTALL_DIR=/path` | Separate from `.llvm/` so LLVM 9 fallback stays intact |
| Point at LLVM 14 (future) | `export PHP_COMPILER_LLVM_PATH="$PWD/.llvm14"` | **Not** default until `vendor/ircmaxell/php-llvm` llvm14 FFI lands |
| Verify (when FFI ready) | `PHP_COMPILER_LLVM_PATH="$PWD/.llvm14" ./script/ci-local.sh` | Dual-support period: LLVM 9 remains canonical until flip in `ci-defaults.env` |

**Current status:** `install-llvm14.sh` mirrors the `install-llvm9.sh` tarball layout (`libLLVM-14.so.1`, `clang-14`, bundled `ld`, gcc-12 crt). `PHPLLVM\Chooser` still selects `libLLVM-9.so.1` first — do not expect JIT/AOT green on LLVM 14 until follow-up PRs add llvm14 FFI + linker updates.

## MiniWebApp gates ([#472](https://github.com/PurHur/php-compiler/issues/472), [#664](https://github.com/PurHur/php-compiler/issues/664))

Defaults are exported from [`script/ci-defaults.env`](../script/ci-defaults.env) and read by `ci-local.sh`, `ci-fast.sh`, and helpers in [`script/ci-common.sh`](../script/ci-common.sh). For the progressive stage ladder (lint → serve → AOT link → execute), see **[miniwebapp-gates.md](miniwebapp-gates.md)** ([#472](https://github.com/PurHur/php-compiler/issues/472)); probe status with [`script/miniwebapp-gates.sh`](../script/miniwebapp-gates.sh), `make miniwebapp-gates`, or `phpc doctor --gates`. **Example web regression bundle** (gates + `ci-fast` MiniWebApp + AOT execute + optional AOT web-smoke; legacy name `north-star1-verify`): [`script/north-star1-verify.sh`](../script/north-star1-verify.sh) / `make north-star1-verify` ([#1845](https://github.com/PurHur/php-compiler/issues/1845), [#1044](https://github.com/PurHur/php-compiler/issues/1044) closed).

| Variable | Default | Script | Notes |
|----------|---------|--------|-------|
| `MINIWEBAPP_VM_CLI_GATE` | `1` | `ci-fast.sh` | PHPUnit `MiniWebApp*VmCli` matrix ([#597](https://github.com/PurHur/php-compiler/issues/597)) |
| `MINIWEBAPP_VM_OOP_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` (`ci_run_miniwebapp_vm_oop`) | `script/check-miniwebapp-vm-oop.sh` — lint zero + VM `phpc serve` PATH_INFO curls ([#2189](https://github.com/PurHur/php-compiler/issues/2189), [#2059](https://github.com/PurHur/php-compiler/issues/2059), default-on [#2293](https://github.com/PurHur/php-compiler/issues/2293)); skipped when `PHP_COMPILER_SKIP_SERVE_TESTS=1`; set `0` to skip |
| `NESTED_RETURN_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `NestedReturn*` — nested `return <call>()` / late static binding VM ([#1888](https://github.com/PurHur/php-compiler/issues/1888), [#1885](https://github.com/PurHur/php-compiler/issues/1885)); set `0` to skip |
| `ATTRIBUTES_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `Attribute*` — PHP 8 attributes VM v1 ([#1904](https://github.com/PurHur/php-compiler/issues/1904), [#1354](https://github.com/PurHur/php-compiler/issues/1354)); set `0` to skip |
| `REHASH_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | `VMTest` paths `array/array_rehash_string_keys`, `array/hashtable_string_keys`, `hashtable_rehash_unset` ([#1956](https://github.com/PurHur/php-compiler/issues/1956), [#66](https://github.com/PurHur/php-compiler/issues/66)); set `0` to skip |
| `REHASH_JIT_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | `JITTest` `language/array_rehash_string_keys_jit` when LLVM+JIT probe ready ([#1959](https://github.com/PurHur/php-compiler/issues/1959), [#66](https://github.com/PurHur/php-compiler/issues/66)); set `0` to skip |
| `COALESCE_COMPLIANCE_GATE` | `1` | `ci-fast.sh` | PHPUnit `Coalesce*` — null coalescing `??` / `??=` VM ([#1960](https://github.com/PurHur/php-compiler/issues/1960), [#99](https://github.com/PurHur/php-compiler/issues/99)); set `0` to skip |
| `JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` (LLVM tail) | PHPUnit `VariableFunction*` — dynamic `$fn()` JIT execute ([#2060](https://github.com/PurHur/php-compiler/issues/2060), [#2055](https://github.com/PurHur/php-compiler/issues/2055), [#1997](https://github.com/PurHur/php-compiler/issues/1997)); skipped when LLVM missing or MCJIT probe fails; set `0` to skip |
| `JIT_SERVER_SUPERGLOBAL_GATE` | `1` | `ci-local.sh` (LLVM tail) | PHPUnit `JitServerSuperglobal` — `bin/jit.php` `$_SERVER` / `PATH_INFO` refresh without recompile ([#2257](https://github.com/PurHur/php-compiler/issues/2257), [#2275](https://github.com/PurHur/php-compiler/issues/2275), [#2292](https://github.com/PurHur/php-compiler/issues/2292)); skipped when LLVM missing or MCJIT probe fails; set `0` to skip |
| `MINIWEBAPP_SERVE_GATE` | `1` | `ci-local.sh`, `ci-fast.sh` | `ServeTest` `@group miniwebapp` ([#641](https://github.com/PurHur/php-compiler/issues/641)) |
| `SESSIONS_WEB_SMOKE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` | `examples-web-smoke.sh --sessions-only` / 005 cookie flash curls ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) |
| `SESSIONS_WEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` (`ci_run_sessions_web_serve_aot_smoke`) | `examples-web-smoke.sh --sessions-only --aot` — 005 `phpc serve --aot` session flash ([#2333](https://github.com/PurHur/php-compiler/issues/2333), default-on [#2371](https://github.com/PurHur/php-compiler/issues/2371)); set `0` to skip |
| `FILE_UPLOAD_WEB_SMOKE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` | `examples-web-smoke.sh --fileupload-only` / 006 multipart upload curls ([#2009](https://github.com/PurHur/php-compiler/issues/2009), [#1999](https://github.com/PurHur/php-compiler/issues/1999)) |
| `FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` (`ci_run_file_upload_web_serve_aot_smoke`) | `examples-web-smoke.sh --fileupload-only --aot` — 006 `phpc serve --aot` multipart POST ([#2333](https://github.com/PurHur/php-compiler/issues/2333), default-on [#2371](https://github.com/PurHur/php-compiler/issues/2371)); set `0` to skip |
| `SESSIONS_WEB_AOT_LINK_GATE` | `1` | `ci-local.sh` (PHPUnit `@group aot-link`) | `ExamplesCompileTest::test005SessionsWebAotLink` — 005 native link ([#1946](https://github.com/PurHur/php-compiler/issues/1946)); set `0` to skip during iteration |
| `SESSIONS_WEB_AOT_SMOKE_GATE` | `0` | `ci-local.sh` (`ci_run_sessions_web_aot_execute`) | `SessionsWebAotExecuteTest` two-request execute ([#1891](https://github.com/PurHur/php-compiler/issues/1891), [#1923](https://github.com/PurHur/php-compiler/issues/1923)) |
| `MINIWEBAPP_WEB_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-web-smoke.sh --miniwebapp-only` ([#664](https://github.com/PurHur/php-compiler/issues/664)) |
| `MINIWEBAPP_WEB_SMOKE_AOT_GATE` | `1` | `ci-local.sh` | `ci_run_miniwebapp_web_smoke_aot` → `examples-web-smoke.sh --miniwebapp-only --aot` ([#1523](https://github.com/PurHur/php-compiler/issues/1523), [#833](https://github.com/PurHur/php-compiler/issues/833)) |
| `MINIWEBAPP_AOT_LINK_GATE` | `1` | `ci-local.sh` (PHPUnit `@group aot-link`) | `ExamplesCompileTest` 003 native link ([#754](https://github.com/PurHur/php-compiler/issues/754)) |
| `MINIWEBAPP_AOT_EXECUTE_GATE` | `1` | `ci-local.sh` after `@group aot-link` (`ci_run_miniwebapp_aot_execute`) | PHPUnit `@group miniwebapp-aot-execute` / `MiniWebAppAotExecuteTest` ([#747](https://github.com/PurHur/php-compiler/issues/747), [#791](https://github.com/PurHur/php-compiler/issues/791)) |
| `MINIWEBAPP_JIT_PROJECT_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` (`ci_run_miniwebapp_jit_project`) | PHPUnit `@group miniwebapp-jit-project` / `MiniWebAppJitProjectTest` — `bin/jit.php` project entry ([#587](https://github.com/PurHur/php-compiler/issues/587), [#2183](https://github.com/PurHur/php-compiler/issues/2183), default-on in ci-fast [#730](https://github.com/PurHur/php-compiler/issues/730)); set `0` to skip |
| `SERVE_JIT_SMOKE_GATE` | `0` | `ci-local.sh` (`ci_run_examples_serve_jit_smoke`), `ci-common.sh` | `examples-serve-jit-smoke.sh` — `phpc serve --jit` curls on 001 (+ 003 when `lint --all` green, + 007 caught POST when `lint` green — [#2274](https://github.com/PurHur/php-compiler/issues/2274), [#2478](https://github.com/PurHur/php-compiler/issues/2478), [#2408](https://github.com/PurHur/php-compiler/issues/2408)); respects `THROWSWEB_SERVE_JIT_SMOKE_GATE`; opt-in in ci-local ([#1900](https://github.com/PurHur/php-compiler/issues/1900)) |
| `EXAMPLES_SELFHOSTPROBE_SMOKE_GATE` | `1` | `ci-fast.sh` | `examples-selfhostprobe-smoke.sh` — 008 VM lint + run ([#2343](https://github.com/PurHur/php-compiler/issues/2343), [#2302](https://github.com/PurHur/php-compiler/issues/2302), [#2240](https://github.com/PurHur/php-compiler/issues/2240)); set `0` for doc-only iteration |
| `SELFHOSTPROBE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` (`ci_run_selfhostprobe_aot_smoke`) | PHPUnit `@group selfhostprobe-aot-execute` / `SelfHostProbeAotExecuteTest`; shell: `EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh` ([#2407](https://github.com/PurHur/php-compiler/issues/2407)); `make north-star3-verify` optional step |
| `EXAMPLES_AOT_SMOKE_GATE` | `1` | `ci-local.sh` | `examples-aot-smoke.sh` after LLVM phases ([#674](https://github.com/PurHur/php-compiler/issues/674)) |
| `EXAMPLES_AOT_SMOKE_ONLY` | unset | `examples-aot-smoke.sh` | Slice e.g. `003` only ([#738](https://github.com/PurHur/php-compiler/issues/738), [#683](https://github.com/PurHur/php-compiler/issues/683)) |
| `DEPLOY_SMOKE_GATE` | `1` | `ci-local.sh` | `deploy-smoke.sh` 001/002 after `examples-aot-smoke` when LLVM ready ([#718](https://github.com/PurHur/php-compiler/issues/718), [#737](https://github.com/PurHur/php-compiler/issues/737)); 003 execute when `DEPLOY_SMOKE_003_EXECUTE=1` or `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `DEPLOY_SMOKE_003_EXECUTE` | `1` | `deploy-smoke.sh`, `ci-local.sh` | Default-on 003 deploy execute E2E ([#1530](https://github.com/PurHur/php-compiler/issues/1530)); also runs when `MINIWEBAPP_AOT_EXECUTE_GATE=1` ([#745](https://github.com/PurHur/php-compiler/issues/745)) |
| `SESSIONS_WEB_DEPLOY_SMOKE_GATE` | `0` | `deploy-smoke.sh`, `ci-local.sh` | Opt-in 005 deploy + `PHPC_DEPLOY_ROOT` session flash CGI ([#1893](https://github.com/PurHur/php-compiler/issues/1893)); VM curls stay on `SESSIONS_WEB_SMOKE_GATE=1` ([#1887](https://github.com/PurHur/php-compiler/issues/1887)) |
| `FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE` | `0` | `deploy-smoke.sh`, `ci-local.sh` | Opt-in 006 deploy + `PHPC_DEPLOY_ROOT` multipart upload CGI ([#2028](https://github.com/PurHur/php-compiler/issues/2028)); VM curls stay on `FILE_UPLOAD_WEB_SMOKE_GATE=1` ([#2009](https://github.com/PurHur/php-compiler/issues/2009)) |
| `THROWSWEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` (`ci_run_throws_web_serve_aot_smoke`) | `examples-web-smoke.sh --throws-only --aot` — 007 `phpc serve --aot` caught invalid POST ([#2390](https://github.com/PurHur/php-compiler/issues/2390), [#2387](https://github.com/PurHur/php-compiler/issues/2387)); set `0` to skip |
| `THROWSWEB_SERVE_JIT_SMOKE_GATE` | `1` | `ci-fast.sh` (`ci_run_throws_web_serve_jit_smoke`) | `examples-web-smoke.sh --throws-only --jit` — 007 `phpc serve --jit` caught invalid POST ([#2435](https://github.com/PurHur/php-compiler/issues/2435), [#2408](https://github.com/PurHur/php-compiler/issues/2408)); set `0` to skip |
| `THROWSWEB_DEPLOY_SMOKE_GATE` | `1` | `deploy-smoke.sh`, `ci-local.sh` | Default-on 007 deploy + `PHPC_DEPLOY_ROOT` caught invalid POST CGI ([#2388](https://github.com/PurHur/php-compiler/issues/2388), [#2124](https://github.com/PurHur/php-compiler/issues/2124)); set `0` to skip; VM curls stay on `THROWS_WEB_SMOKE_GATE=1` ([#2125](https://github.com/PurHur/php-compiler/issues/2125)) |
| `FASTCGI_WEB_SMOKE_GATE` | `1` | `ci-fast.sh`, `ci-local.sh` | `examples-web-smoke.sh --fastcgi-only` / 009 health + PATH_INFO curls ([#2351](https://github.com/PurHur/php-compiler/issues/2351), default-on [#2369](https://github.com/PurHur/php-compiler/issues/2369)); set `0` to skip |
| `FASTCGI_WEB_AOT_SMOKE_GATE` | `1` | `ci-local.sh` (`ci_run_fastcgi_web_aot_execute`) | `FastCGIWebAotExecuteTest` / `EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh` ([#2352](https://github.com/PurHur/php-compiler/issues/2352), default-on [#2369](https://github.com/PurHur/php-compiler/issues/2369)); set `0` to skip |
| `DEPLOY_SMOKE_ALL` | `0` | `Makefile` `deploy-smoke` | When `1`, `make deploy-smoke` delegates to `deploy-smoke-all.sh` (same as `make deploy-smoke-all`) ([#2077](https://github.com/PurHur/php-compiler/issues/2077)) |
| `FASTCGI_WEB_DEPLOY_SMOKE_GATE` | `0` | `deploy-smoke.sh`, `ci-local.sh` | Opt-in 009 deploy + `PHPC_DEPLOY_ROOT` health + PATH_INFO CGI ([#2359](https://github.com/PurHur/php-compiler/issues/2359)); VM curls stay on `FASTCGI_WEB_SMOKE_GATE=1` ([#2351](https://github.com/PurHur/php-compiler/issues/2351)) |
| `make deploy-smoke-all` | n/a | `script/deploy-smoke-all.sh` | Full deploy ladder 001–003 + opt-in 005/006/007/009; prints skip reasons when gates `0` — probe with `./phpc doctor --gates` ([#2077](https://github.com/PurHur/php-compiler/issues/2077), [#2359](https://github.com/PurHur/php-compiler/issues/2359)) |
| `BOOTSTRAP_SELFHOST_PROBE_GATE` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `make bootstrap-selfhost-probe` on `compiler_minimal` ([#829](https://github.com/PurHur/php-compiler/issues/829)) |
| `BOOTSTRAP_SELFHOST_PROBE_UPDATE` | `0` | `ci_run_bootstrap_selfhost_probe` | Pass `--update-inventory` to probe (dev only) |
| `BOOTSTRAP_LOOP_PROBE_GATE` | `0` | `ci-fast.sh` (LLVM opt-in) | `./script/bootstrap-loop-probe.sh --dry-run` M4 ladder ([#1777](https://github.com/PurHur/php-compiler/issues/1777), [#1498](https://github.com/PurHur/php-compiler/issues/1498), [#1929](https://github.com/PurHur/php-compiler/issues/1929)) |
| `BOOTSTRAP_M4_LOOP_PROBE` | `1` | `ci-local.sh` (LLVM tail, after M3 strict gates) | Full `bootstrap-loop-probe.sh` ladder (M0/M2/M3 + gen-1→gen-3); opt-out `=0` ([#2780](https://github.com/PurHur/php-compiler/issues/2780), [#2058](https://github.com/PurHur/php-compiler/issues/2058)) |
| `BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE` | `0` | `ci-local.sh` (LLVM tail) | Full M4 spine probe (bigger than the minimal spine smoke); opt-in during self-host ladder iteration ([#2380](https://github.com/PurHur/php-compiler/issues/2380)) |
| `BOOTSTRAP_WAVE_CHECK` | unset → `1` in `ci-local.sh` llvm tail; set `0` to skip | `ci-local.sh`, `ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`) | `./script/bootstrap-wave-check.sh --fail-fast` after `@group aot-lint` |
| `BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE` | `1` | `ci-local.sh` (LLVM tail, last step) | `./script/bootstrap-wave-check.sh --vendor-absent --fail-fast` — lib spine link with `vendor/` renamed away ([#8712](https://github.com/PurHur/php-compiler/issues/8712), [#3052](https://github.com/PurHur/php-compiler/issues/3052)); skips with exit 2 when LLVM or prelinked `.o` missing |
| `BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT` | unset → `1` when gate on; set `0` to skip vendor-absent slice | `ci-common.sh` (`ci_run_bootstrap_wave_check_vendor_absent`) | Dev escape hatch when prelinked blobs absent locally |
| `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE` | `1` | `ci-local.sh` (LLVM tail, after wave-check) | `bootstrap-selfhost-helloworld-probe.sh` with strict native emit ([#1526](https://github.com/PurHur/php-compiler/issues/1526), default-on [#1866](https://github.com/PurHur/php-compiler/issues/1866)); opt-out `=0` |
| `BOOTSTRAP_M4_GEN2_STRICT_GATE` | `1` | `ci-local.sh` (LLVM tail, after M3 strict gates) | `bootstrap-loop-gen1-link.sh` with `BOOTSTRAP_M4_GEN2_STRICT=1` — native gen-2 emit, no Zend fallback ([#2075](https://github.com/PurHur/php-compiler/issues/2075), default-on [#2112](https://github.com/PurHur/php-compiler/issues/2112)); opt-out `=0` |
| `BOOTSTRAP_M5_DRIVER_GATE` | `0` | `ci-local.sh` (LLVM tail) | Build the M5 self-host native compile driver (pre-req for vendor object compilation); opt-in ([#2380](https://github.com/PurHur/php-compiler/issues/2380)) |
| `BOOTSTRAP_M5_DRIVER_SMOKE_GATE` | `0` | `ci-local.sh` (LLVM tail) | Smoke-check the M5 driver after build; opt-in ([#2380](https://github.com/PurHur/php-compiler/issues/2380)) |
| `BOOTSTRAP_SPINE_PHPCFG_PARSE_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | Minimal PHPCfg parse sanity for spine subset (guards `test/selfhost/compiler_lib_spine_smoke/main.php`) ([#2575](https://github.com/PurHur/php-compiler/issues/2575)) |
| `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE` | `1` | `ci-local.sh` (LLVM tail, after HelloWorld strict) | `bootstrap-selfhost-compile-smoke-probe.sh` partial Zend emit + native run ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); set `0` to skip |
| `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE` | `1` | `ci-local.sh` (LLVM tail, after compile-smoke probe) | same probe with `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1` — no Zend fallback ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); opt-out `=0` during LLVM iteration ([#2165](https://github.com/PurHur/php-compiler/issues/2165)) |
| `BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE` | `1` | `ci-local.sh` (LLVM tail) | `bootstrap-selfhost-runtime-compile-smoke.sh` partial Zend emit + native run on `lib/Runtime.php` slice ([#2294](https://github.com/PurHur/php-compiler/issues/2294)); set `0` to skip |
| `BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE` | `0` | `ci-local.sh` (LLVM tail, after runtime probe) | same probe with `BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT=1` ([#2294](https://github.com/PurHur/php-compiler/issues/2294)); set `1` when native runtime emit stable |
| `BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE` | `1` | `ci-local.sh` (LLVM tail, after selfhost-probe) | `bootstrap-selfhost-lib-spine-vm-smoke.sh` — M2 spine binary via `bin/vm.php -r` path ([#1846](https://github.com/PurHur/php-compiler/issues/1846), [#1867](https://github.com/PurHur/php-compiler/issues/1867)); set `0` for spine-only link PRs |
| `BOOTSTRAP_VM_DRIVER_EXECUTE_GATE` | `1` | `ci-local.sh` (LLVM tail, after lib-spine-vm-smoke) | `bootstrap-selfhost-vm-driver-execute-probe.sh` — native `PHP_COMPILER_VM_DRIVER_EXECUTE=1` gate (**~20ms** when `build/selfhost-lib-spine-smoke` exists; seeds from `prelinked/bootstrap-gen0` when missing; stale SHA does not force relink — [#2201](https://github.com/PurHur/php-compiler/issues/2201), [#2227](https://github.com/PurHur/php-compiler/issues/2227)); full spine rebuild: `BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK=1`; set gate `0` to skip during spine-only PRs |
| `COMPILER_DRIVER_SMOKE_GATE` | `1` | `ci-local.sh` (LLVM tail, after vm-driver-execute-probe) | `bootstrap-selfhost-compiler-driver-smoke-link.sh` — M3 `compiler_driver_smoke` native link + run ([#2136](https://github.com/PurHur/php-compiler/issues/2136), opt-in wiring [#2137](https://github.com/PurHur/php-compiler/issues/2137), default-on [#2168](https://github.com/PurHur/php-compiler/issues/2168)); set `0` to skip driver smoke during LLVM iteration |
| `BOOTSTRAP_JIT_UNIT_PROBE_GATE` | `0` | `ci-local.sh` (LLVM tail, after compiler-driver-smoke) | `bootstrap-selfhost-jit-unit-probe.sh` — M3 `jit_unit_probe` native link + run ([#2332](https://github.com/PurHur/php-compiler/issues/2332), opt-in wiring [#2361](https://github.com/PurHur/php-compiler/issues/2361)); set `1` for opt-in gate |
| `BOOTSTRAP_VM_UNIT_PROBE_GATE` | `0` | `ci-local.sh` (LLVM tail, after JIT unit probe) | `bootstrap-selfhost-vm-unit-probe.sh` — M3 `vm_unit_probe` native link (+ optional `BOOTSTRAP_VM_UNIT_PROBE_RUN=1` VM echo run) ([#2354](https://github.com/PurHur/php-compiler/issues/2354)); set `1` for opt-in gate; default-on follow-up [#2368](https://github.com/PurHur/php-compiler/issues/2368) |
| `BOOTSTRAP_PARSER_UNIT_PROBE_GATE` | `1` | `ci-local.sh` (LLVM tail, after VM unit probe) | `bootstrap-selfhost-parser-unit-probe.sh` — M3 `parser_unit_probe` CFG parse front-end native link ([#2409](https://github.com/PurHur/php-compiler/issues/2409), opt-in wiring [#2417](https://github.com/PurHur/php-compiler/issues/2417), default-on [#2419](https://github.com/PurHur/php-compiler/issues/2419)); set `0` to skip during LLVM iteration |
| `BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE` | `1` | `ci-local.sh` (LLVM tail, after parser unit probe) | `bootstrap-selfhost-types-unit-probe.sh` — M3 `types_unit_probe` native link + run ([#2430](https://github.com/PurHur/php-compiler/issues/2430), opt-in wiring [#2433](https://github.com/PurHur/php-compiler/issues/2433), default-on [#2436](https://github.com/PurHur/php-compiler/issues/2436)); set `0` to skip during LLVM iteration |
| `BOOTSTRAP_M3_EMIT_TU_EXECUTE_GATE` | `0` | `ci-local.sh` (LLVM tail, after PHPTypes unit probe) | `vendor/bin/phpunit --group selfhost-m3-emit` via `bootstrap-m3-emit-tu-execute.sh` — `runtime_compile_smoke/compile_driver.php` link/compile/run ([#2444](https://github.com/PurHur/php-compiler/issues/2444)); set `1` for opt-in; default-on after [#2442](https://github.com/PurHur/php-compiler/issues/2442) green |
| `CI_FAST_BOOTSTRAP` | `0` | `ci-fast.sh` | Optional llvm tail: bootstrap aot-lint + probe + wave-check when LLVM 9 present |
| `JIT_PREFLIGHT_GATE` | `0` | `ci-fast.sh` | Early MCJIT probe after `composer install` ([#728](https://github.com/PurHur/php-compiler/issues/728)) |
| `NORTH_STAR2_VERIFY_GATE` | `1` | `ci-fast.sh` | `./script/north-star2-verify.sh` presenter when script exists ([#1928](https://github.com/PurHur/php-compiler/issues/1928), [#2051](https://github.com/PurHur/php-compiler/issues/2051)); set `0` to opt out (no LLVM / doc-only iteration) |
| `NORTH_STAR2_THROWSWEB_GATE` | `1` | `north-star2-verify.sh` | Optional step 6: `check-init-throwsweb-parity.sh` + `make examples-throws-smoke` when 007 tree present ([#2177](https://github.com/PurHur/php-compiler/issues/2177)); skips when loopback bind fails; set `0` on harness hosts without TCP loopback |
| `NORTH_STAR3_VERIFY_GATE` | `0` | `ci-fast.sh` | `make north-star3-verify` — 008 + M3 unit probes when script exists ([#2396](https://github.com/PurHur/php-compiler/issues/2396), [#2360](https://github.com/PurHur/php-compiler/issues/2360)); LLVM probes skip when absent; set `1` for opt-in |
| `NORTH_STAR4_VERIFY_GATE` | `0` | `ci-local.sh` (LLVM tail, after M3/M4 probes) | `make north-star4-verify` — inventory + M3 strict + gen-1→gen-2 + loop probe + gen-2→gen-3 ([#2429](https://github.com/PurHur/php-compiler/issues/2429), [#2379](https://github.com/PurHur/php-compiler/issues/2379)); set `1` for opt-in; set `0` to skip |
| `NORTH_STAR5_VERIFY_FAST_GATE` | `1` | `ci-fast.sh` (after north-star3) | `make north-star5-verify-fast` — M5 PR presenter (~1–2 min): inventory + spine + committed prelink blobs + VM probe; **not** `--strict` ([#1492](https://github.com/PurHur/php-compiler/issues/1492)); set `0` to skip consolidated presenter (individual gates still run) |
| `NORTH_STAR5_VERIFY_STRICT_GATE` | `0` | n/a (manual / nightly) | Full `north-star5-verify --strict` (~1h); never default-on in CI |
| `BOOTSTRAP_TEST_SUBSET_GATE` | `0` | `ci-fast.sh` (after inventory checks) | `./script/bootstrap-test-subset.sh` — same as `phpc test --bootstrap` ([#2069](https://github.com/PurHur/php-compiler/issues/2069)); set `1` for NS2 fast path hook |
| `BOOTSTRAP_TEST_SUBSET_STRICT` | `0` | `ci_run_bootstrap_test_subset` | Pass `--strict` to subset script (M3 HelloWorld strict when LLVM ready); does not run in ci-fast by default |
| `phpc test --bootstrap` | n/a | `script/bootstrap-test-subset.sh` | Inventory `--check` + `SELFHOST_SPINE_COUNT_SYNC_GATE` spine sync (no LLVM link by default); `BOOTSTRAP_TEST_SUBSET_VM_SMOKE=1` for M2 VM spine smoke; `--strict` sets `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1` for M3 probe ([#1961](https://github.com/PurHur/php-compiler/issues/1961)) |
| `M2_SPINE_ISSUE_HYGIENE_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-m2-spine-issue-hygiene.php` — stale `m2-spine-unit` tickets ([#1819](https://github.com/PurHur/php-compiler/issues/1819), [#1808](https://github.com/PurHur/php-compiler/issues/1808)); set `0` for bulk spine PRs |
| `WAVE3_ROADMAP_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-wave3-roadmap-sync.php` ([#1802](https://github.com/PurHur/php-compiler/issues/1802), [#1814](https://github.com/PurHur/php-compiler/issues/1814)); set `0` for doc-only iteration |
| `EXAMPLES_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-examples-readme-sync.php` ([#1822](https://github.com/PurHur/php-compiler/issues/1822), [#1531](https://github.com/PurHur/php-compiler/issues/1531)); set `0` for doc-only iteration |
| `EXAMPLES_LADDER_DISCOVERY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-examples-ladder-discovery.php` — `examples/` dirs ↔ `ExamplesCompileTest` ([#1913](https://github.com/PurHur/php-compiler/issues/1913)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_005_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-005-row.php` — `005-SessionsWeb` run matrix + benchmark row vs `rebuild-examples.php` ([#1930](https://github.com/PurHur/php-compiler/issues/1930), [#1953](https://github.com/PurHur/php-compiler/issues/1953)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-006-row.php` — `006-FileUploadWeb` run matrix vs `FILE_UPLOAD_WEB_*` gate defaults ([#2018](https://github.com/PurHur/php-compiler/issues/2018), [#2052](https://github.com/PurHur/php-compiler/issues/2052)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_009_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-009-sync.php` — `009-FastCGIWeb` benchmark row vs `BENCH_FASTCGIWEB*` ([#2370](https://github.com/PurHur/php-compiler/issues/2370)); set `0` for doc-only iteration |
| `REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-rebuild-examples-003-jit-project-sync.php` — `003-MiniWebApp (project JIT)` benchmark sub-row when `MiniWebAppJitProjectTest` is non-skipped ([#2334](https://github.com/PurHur/php-compiler/issues/2334), blocked on [#2183](https://github.com/PurHur/php-compiler/issues/2183)); set `1` after project-JIT column lands |
| `CAPABILITIES_SESSIONSWEB_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-capabilities-sessionsweb-sync.php` — **005-SessionsWeb** rows in `docs/capabilities-syntax.md` ([#1947](https://github.com/PurHur/php-compiler/issues/1947)); set `0` for matrix-only edits |
| `CAPABILITIES_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-capabilities-fileuploadweb-sync.php` — `006` + `move_uploaded_file` / `$_FILES` rows in capability docs ([#2019](https://github.com/PurHur/php-compiler/issues/2019), [#2068](https://github.com/PurHur/php-compiler/issues/2068)); set `0` for matrix-only edits |
| `CAPABILITIES_THROWS_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-capabilities-throws-sync.php` — `try`/`catch`/`throw` + **007-ThrowsWeb** rows in `docs/capabilities-syntax.md` ([#2144](https://github.com/PurHur/php-compiler/issues/2144), [#2103](https://github.com/PurHur/php-compiler/issues/2103), default-on [#2156](https://github.com/PurHur/php-compiler/issues/2156)); set `0` for matrix-only edits |
| `CAPABILITIES_OOP_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-capabilities-oop-sync.php` — **003-MiniWebApp** OOP rows (`ClassMethod`, `Expr_MethodCall`, `__construct`) in `docs/capabilities-syntax.md` ([#2190](https://github.com/PurHur/php-compiler/issues/2190)); set `1` when editing OOP matrix rows |
| `ROOT_README_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — 003/005/006/007 row parity ([#1832](https://github.com/PurHur/php-compiler/issues/1832), [#1525](https://github.com/PurHur/php-compiler/issues/1525), [#2017](https://github.com/PurHur/php-compiler/issues/2017), [#2094](https://github.com/PurHur/php-compiler/issues/2094)); set `0` for doc-only iteration |
| `DEVELOPMENT_STATUS_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-development-status-sync.php` — `docs/pages/development-status.md` vs examples/README 006 + NS2 M3 rows ([#2067](https://github.com/PurHur/php-compiler/issues/2067), [#2039](https://github.com/PurHur/php-compiler/issues/2039), default-on [#2083](https://github.com/PurHur/php-compiler/issues/2083)); set `0` for doc-only iteration |
| `DEVELOPMENT_STATUS_007_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-development-status-sync.php` — **007-ThrowsWeb** row + `THROWS_WEB_SMOKE_GATE` / `#2093` / `#2101` needles ([#2145](https://github.com/PurHur/php-compiler/issues/2145), default-on [#2155](https://github.com/PurHur/php-compiler/issues/2155)); set `0` for doc-only iteration |
| `ROOT_README_006_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — stale 006 gate phrases when defaults are on ([#2017](https://github.com/PurHur/php-compiler/issues/2017), [#2052](https://github.com/PurHur/php-compiler/issues/2052)); set `0` for doc-only iteration |
| `ROOT_README_007_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — stale 007 gate phrases when `THROWS_WEB_SMOKE_GATE` default is on ([#2094](https://github.com/PurHur/php-compiler/issues/2094)); set `0` for doc-only iteration |
| `ROOT_README_008_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-sync.php` — **008-SelfHostProbe** shipped row + stale `EXAMPLES_SELFHOSTPROBE_SMOKE_GATE` / `SELFHOSTPROBE_AOT_SMOKE_GATE` phrases when defaults are on ([#2229](https://github.com/PurHur/php-compiler/issues/2229)); set `1` to enable; flip default-on after [#2217](https://github.com/PurHur/php-compiler/issues/2217) |
| `GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-getting-started-selfhostprobe-sync.php` — **008-SelfHostProbe** presenter §6 in `docs/GETTING-STARTED.md` (harness-safe commands, no raw bind-mount) ([#2230](https://github.com/PurHur/php-compiler/issues/2230)); set `1` after [#2222](https://github.com/PurHur/php-compiler/issues/2222) §6 lands |
| `ROOT_README_009_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-root-readme-009-sync.php` — **009-FastCGIWeb** shipped row + FastCGI **#173** / **#2331** needles ([#2353](https://github.com/PurHur/php-compiler/issues/2353)); set `0` for doc-only iteration |
| `DEVELOPMENT_STATUS_009_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-development-status-009-sync.php` — **009-FastCGIWeb** row in `development-status.md` + `FASTCGI_WEB_SMOKE_GATE` wording ([#2353](https://github.com/PurHur/php-compiler/issues/2353)); set `0` for doc-only iteration |
| `SELFHOST_SPINE_COUNT_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-count-sync.php` (canonical `script/bootstrap-spine-count.php`, [#1834](https://github.com/PurHur/php-compiler/issues/1834), [#1872](https://github.com/PurHur/php-compiler/issues/1872)); set `0` for bulk spine PRs |
| `SELFHOST_SPINE_COVERAGE_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-coverage-sync.php` — inventory paths vs `compiler_lib_spine_smoke` ([#1955](https://github.com/PurHur/php-compiler/issues/1955), [#1945](https://github.com/PurHur/php-compiler/issues/1945)); set `0` for bulk spine path-only PRs |
| `SELFHOST_SPINE_DEFERRED_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-deferred-sync.php` — `bootstrap-spine-deferred-lib.php` vs M2 deferred footnotes ([#2202](https://github.com/PurHur/php-compiler/issues/2202)); set `0` for doc-only iteration; empty deferred when [#2134](https://github.com/PurHur/php-compiler/issues/2134) closes |
| `SELFHOST_SPINE_SIDECAR_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-spine-sidecar-sync.php` — `prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha` vs spine entry SHA-1 ([#8703](https://github.com/PurHur/php-compiler/issues/8703)); waive with `BOOTSTRAP_ALLOW_STALE_SIDECAR=1` for intentional blob batch PRs |
| `SELFHOST_M4_GEN2_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-selfhost-m4-gen2-sync.php` — M4 gen-2 `emit_path=*` docs vs `bootstrap-loop-gen1-link.sh` ([#2115](https://github.com/PurHur/php-compiler/issues/2115), default-on [#2175](https://github.com/PurHur/php-compiler/issues/2175)); set `0` for doc-only iteration |
| `BOOTSTRAP_M3_STRICT_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-bootstrap-m3-strict-sync.php` — M3 compile-smoke `emit_path=*` docs vs `bootstrap-selfhost-compile-smoke-probe.sh` ([#2176](https://github.com/PurHur/php-compiler/issues/2176)); set `1` when editing M3 docs/scripts; flip default-on after [#2165](https://github.com/PurHur/php-compiler/issues/2165) |
| `BOOTSTRAP_M5_DOC_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-bootstrap-m5-doc-sync.php` — `docs/bootstrap-m5-fast-path.md` vs `script/m3-allowlist-snapshot.txt` ([#1984](https://github.com/PurHur/php-compiler/issues/1984)); set `0` for doc-only iteration |
| `BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `php script/bootstrap-vendor-inventory.php --check` — [`docs/bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md) vs live `vendor/` scan ([#2030](https://github.com/PurHur/php-compiler/issues/2030), default-on [#2040](https://github.com/PurHur/php-compiler/issues/2040)); set `0` for vendor-only iteration |
| `BOOTSTRAP_VENDOR_PRELINK_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `php script/bootstrap-vendor-objects.php --check` — literal vendor bundles + manifest ([#1416](https://github.com/PurHur/php-compiler/issues/1416)); set `0` while editing bundles |
| `BOOTSTRAP_VENDOR_REBUILD_AUDIT` | `0` | `north-star5-verify.sh --fast` (opt-in) | `./script/bootstrap-vendor-native-rebuild-audit.sh` — native rebuild SHA-256 vs committed vendor `.o` ([#8718](https://github.com/PurHur/php-compiler/issues/8718)); monthly / before vendor-prelink PRs |
| `BOOTSTRAP_VENDOR_PRELINK_GATE` | `0` | `bootstrap-wave-check.sh` | `make bootstrap-vendor-objects` — AOT vendor `.o` into `prelinked/bootstrap-vendor/` (opt-in until compile green) |
| `M3_ALLOWLIST_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-m3-allowlist-snapshot.php` ([#1905](https://github.com/PurHur/php-compiler/issues/1905)); set `0` for bulk symbol PRs |
| `BOOTSTRAP_INVENTORY_LINT_SYNC_GATE` | `0` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-bootstrap-inventory-lint-sync.php` — `phpc lint --bootstrap-inventory` vs `docs/bootstrap-inventory-lint-snapshot.json` ([#2210](https://github.com/PurHur/php-compiler/issues/2210)); probe ladder: `./phpc doctor --gates \| grep -i bootstrap_inventory` ([#2228](https://github.com/PurHur/php-compiler/issues/2228)); set `1` when editing inventory lint report; regenerate with `php script/bootstrap-inventory-lint-snapshot.php --write` |
| `BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-bootstrap-inventory-triage-sync.php` — `bootstrap-inventory-triage.php --json --top 50` vs `docs/bootstrap-inventory-triage-top50.json` ([#2265](https://github.com/PurHur/php-compiler/issues/2265), [#2389](https://github.com/PurHur/php-compiler/issues/2389)); set `0` to skip during triage iteration; regenerate with `php script/bootstrap-inventory-triage.php --json --top 50 > docs/bootstrap-inventory-triage-top50.json` |
| `STDLIB_JIT_DEFERRED_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-stdlib-jit-deferred-sync.php` — `docs/stdlib-jit-audit.md` + `docs/capabilities.md` vs `script/stdlib-jit-deferred-lib.php` open deferrals ([#2465](https://github.com/PurHur/php-compiler/issues/2465), default-on [#2476](https://github.com/PurHur/php-compiler/issues/2476)); probe: `./phpc doctor --gates \| grep -i stdlib_jit`; set `0` to opt out |
| `DOCTOR_GATES_MATRIX_SYNC_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-doctor-gates-sync.php` — `ci-defaults.env` tracked gates vs `phpc doctor --gates` / `docs/local-ci-matrix.md` ([#2380](https://github.com/PurHur/php-compiler/issues/2380)); set `0` for doctor-only iteration |
| `DOCS_HARNESS_HYGIENE_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-docs-harness-hygiene.php` — guard `README.md` / `docs/` against recommending raw bind-mount `docker run` to `/compiler` ([#2485](https://github.com/PurHur/php-compiler/issues/2485)); set `0` to skip during doc-only iteration |
| `INIT_MINIWEBAPP_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-miniwebapp-parity.sh` — `examples/003-MiniWebApp/` ↔ `templates/init-miniwebapp/` ([#2057](https://github.com/PurHur/php-compiler/issues/2057), [#695](https://github.com/PurHur/php-compiler/issues/695)); set `0` for doc-only iteration |
| `MINIWEBAPP_LINT_ZERO_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-miniwebapp-lint-zero.php` — zero unsupported nodes under `examples/003-MiniWebApp/` ([#2078](https://github.com/PurHur/php-compiler/issues/2078)); set `0` during lint iteration; follow-on [#2059](https://github.com/PurHur/php-compiler/issues/2059) serve e2e |
| `INIT_SESSIONSWEB_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-sessionsweb-parity.sh` — `examples/005-SessionsWeb/` ↔ `templates/init-sessionsweb/` ([#1902](https://github.com/PurHur/php-compiler/issues/1902)); set `0` for doc-only iteration |
| `INIT_FILEUPLOAD_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-fileupload-parity.sh` — `examples/006-FileUploadWeb/` ↔ `templates/init-fileupload/` ([#2004](https://github.com/PurHur/php-compiler/issues/2004), default-on [#2020](https://github.com/PurHur/php-compiler/issues/2020)); set `0` for doc-only iteration |
| `INIT_THROWSWEB_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-throwsweb-parity.sh` — `examples/007-ThrowsWeb/` ↔ `templates/init-throwsweb/` ([#2086](https://github.com/PurHur/php-compiler/issues/2086), default-on [#2127](https://github.com/PurHur/php-compiler/issues/2127)); set `0` for doc-only iteration |
| `INIT_SELFHOSTPROBE_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-selfhostprobe-parity.sh` — `examples/008-SelfHostProbe/` ↔ `templates/init-selfhostprobe/` ([#2220](https://github.com/PurHur/php-compiler/issues/2220)); set `0` for doc-only iteration |
| `INIT_FASTCGIWEB_PARITY_GATE` | `1` | `ci-fast.sh` (`ci_run_inventory_checks`) | `script/check-init-fastcgiweb-parity.sh` — `examples/009-FastCGIWeb/` ↔ `templates/init-fastcgiweb/` ([#2342](https://github.com/PurHur/php-compiler/issues/2342)); set `0` for doc-only iteration |
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
| AOT serve | `SESSIONS_WEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` · `examples-web-smoke.sh --sessions-only --aot` ([#2333](https://github.com/PurHur/php-compiler/issues/2333), [#2371](https://github.com/PurHur/php-compiler/issues/2371)); set `0` to skip |
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
| AOT serve | `FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` · `examples-web-smoke.sh --fileupload-only --aot` ([#2333](https://github.com/PurHur/php-compiler/issues/2333), [#2371](https://github.com/PurHur/php-compiler/issues/2371)); set `0` to skip |
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

Progressive ladder (VM throw/catch → AOT link → AOT execute → deploy CGI). VM smoke default-on ([#2125](https://github.com/PurHur/php-compiler/issues/2125), [#2093](https://github.com/PurHur/php-compiler/issues/2093)); AOT serve default-on ([#2390](https://github.com/PurHur/php-compiler/issues/2390), [#2387](https://github.com/PurHur/php-compiler/issues/2387)); JIT serve default-on ([#2435](https://github.com/PurHur/php-compiler/issues/2435), [#2408](https://github.com/PurHur/php-compiler/issues/2408)); AOT gates default-on ([#2135](https://github.com/PurHur/php-compiler/issues/2135), [#2157](https://github.com/PurHur/php-compiler/issues/2157)). Copy-paste ladder: `./phpc doctor --gates` (**#2102**).

**`ci-local.sh` llvm tail** (mirror **006** link → execute): `ci_run_aot_link_phpunit` (`@group aot-link`, honors `THROWSWEB_AOT_LINK_GATE`) runs before `ci_run_throws_web_aot_execute` (`@group throwsweb-aot-execute`, honors `THROWSWEB_AOT_SMOKE_GATE`) — audit **#2178**; default-on **#2135** after **#2157** ✅.

| Stage | Variable | Default | When enabled |
|-------|----------|---------|--------------|
| VM throw/catch | `THROWS_WEB_SMOKE_GATE` | `1` | `make examples-throws-smoke` · `ci-fast.sh` ([#2125](https://github.com/PurHur/php-compiler/issues/2125), [#2093](https://github.com/PurHur/php-compiler/issues/2093)) |
| VM uncaught 500 | `THROWSWEB_UNCAUGHT_500_GATE` | `0` | `THROWSWEB_UNCAUGHT_500_GATE=1 ./script/examples-web-smoke.sh --throws-only` · `ci-fast.sh` ([#2200](https://github.com/PurHur/php-compiler/issues/2200)) |
| AOT serve | `THROWSWEB_SERVE_AOT_SMOKE_GATE` | `1` | `ci-local.sh` · `examples-web-smoke.sh --throws-only --aot` ([#2390](https://github.com/PurHur/php-compiler/issues/2390), [#2387](https://github.com/PurHur/php-compiler/issues/2387)); set `0` to skip |
| JIT serve | `THROWSWEB_SERVE_JIT_SMOKE_GATE` | `1` | `make examples-throws-jit-smoke` · `ci-fast.sh` ([#2435](https://github.com/PurHur/php-compiler/issues/2435), [#2408](https://github.com/PurHur/php-compiler/issues/2408)); set `0` to skip |
| AOT link | `THROWSWEB_AOT_LINK_GATE` | `1` | `./script/ci-local.sh --filter test007ThrowsWebAotLink` ([#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2135](https://github.com/PurHur/php-compiler/issues/2135)); set `0` to skip during iteration |
| AOT execute | `THROWSWEB_AOT_SMOKE_GATE` | `1` | `ThrowsWebAotExecuteTest` or `EXAMPLES_AOT_SMOKE_ONLY=007 ./script/examples-aot-smoke.sh` ([#2101](https://github.com/PurHur/php-compiler/issues/2101), [#2135](https://github.com/PurHur/php-compiler/issues/2135)) |
| Deploy CGI | `THROWSWEB_DEPLOY_SMOKE_GATE` | `1` | `make deploy-smoke-all` ([#2388](https://github.com/PurHur/php-compiler/issues/2388), [#2124](https://github.com/PurHur/php-compiler/issues/2124), [#2077](https://github.com/PurHur/php-compiler/issues/2077)); `make examples-throwsweb-deploy-smoke` (007 only); set `0` to skip |

```bash
./phpc doctor --gates | grep -E 'THROWS|007-ThrowsWeb'
THROWS_WEB_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only
THROWSWEB_UNCAUGHT_500_GATE=1 ./script/examples-web-smoke.sh --throws-only
THROWSWEB_SERVE_AOT_SMOKE_GATE=0 ./script/examples-web-smoke.sh --throws-only --aot   # opt-out
./script/examples-web-smoke.sh --throws-only --aot   # default-on (#2390)
THROWSWEB_SERVE_JIT_SMOKE_GATE=0 ./script/examples-web-smoke.sh --throws-only --jit   # opt-out
./script/examples-web-smoke.sh --throws-only --jit   # default-on (#2435)
THROWSWEB_AOT_LINK_GATE=1 THROWSWEB_AOT_SMOKE_GATE=1 ./script/ci-local.sh --filter ThrowsWebAot
make examples-throwsweb-deploy-smoke
./script/deploy-smoke.sh --example 007   # default-on (#2388)
THROWSWEB_DEPLOY_SMOKE_GATE=0 ./script/deploy-smoke.sh --example 007   # opt-out
```

## 009-FastCGIWeb gates ([#2331](https://github.com/PurHur/php-compiler/issues/2331), [#2351](https://github.com/PurHur/php-compiler/issues/2351))

Progressive ladder (VM serve → AOT execute → deploy CGI). VM serve and AOT execute smokes are default-on in `ci-fast` / `ci-local` ([#2369](https://github.com/PurHur/php-compiler/issues/2369)). Copy-paste ladder: `./phpc doctor --gates` (grep `009-FastCGIWeb`).

| Stage | Variable | Default | When enabled |
|-------|----------|---------|--------------|
| FastCGI PHPUnit adapter | `FASTCGI_SMOKE_GATE` | `0` | `FASTCGI_SMOKE_GATE=1 ./script/ci-local.sh --filter 'FastCgiRecordTest\|FastCgiTest'` ([#173](https://github.com/PurHur/php-compiler/issues/173), [#1899](https://github.com/PurHur/php-compiler/issues/1899)) |
| FastCGI worker CLI | _(n/a)_ | — | `./phpc fcgi --project examples/009-FastCGIWeb` · `./phpc fcgi --help` ([#2427](https://github.com/PurHur/php-compiler/issues/2427)) |
| VM health + PATH_INFO | `FASTCGI_WEB_SMOKE_GATE` | `1` | `make examples-fastcgiweb-smoke` · `ci-fast` default ([#2351](https://github.com/PurHur/php-compiler/issues/2351), [#2369](https://github.com/PurHur/php-compiler/issues/2369)) |
| AOT execute | `FASTCGI_WEB_AOT_SMOKE_GATE` | `1` | `EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh` · `ci-local` LLVM tail ([#2352](https://github.com/PurHur/php-compiler/issues/2352), [#2369](https://github.com/PurHur/php-compiler/issues/2369)) |
| Deploy CGI | `FASTCGI_WEB_DEPLOY_SMOKE_GATE` | `0` | `FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all` ([#2359](https://github.com/PurHur/php-compiler/issues/2359)); `make examples-fastcgiweb-deploy-smoke` (009 only) |

```bash
./phpc doctor --gates | grep -E 'FASTCGI|009-FastCGIWeb'
FASTCGI_SMOKE_GATE=1 ./script/ci-local.sh --filter 'FastCgiRecordTest|FastCgiTest'
make examples-fastcgiweb-smoke
./script/examples-web-smoke.sh --fastcgi-only
EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh
FASTCGI_WEB_SMOKE_GATE=0 ./script/ci-fast.sh   # opt-out 009 VM serve curls (#2369)
FASTCGI_WEB_AOT_SMOKE_GATE=0 ./script/ci-local.sh   # opt-out 009 AOT execute (#2369)
make examples-fastcgiweb-deploy-smoke
FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 009
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
# Docker (harness-safe):
./script/docker-ci-local.sh fast
# or targeted:
./script/docker-exec.sh -- ./script/ci-fast.sh
```

When the script is missing, CI prints a skip message and exits **0**. With LLVM 9 present, runs `./script/north-star2-verify.sh` (full tail); without LLVM, passes `--skip-llvm-tail`. Step 6 (**007-ThrowsWeb**) runs when `NORTH_STAR2_THROWSWEB_GATE=1` (default) even without LLVM; VM smoke skips when loopback bind fails (`NORTH_STAR2_THROWSWEB_GATE=0` to opt out).

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
