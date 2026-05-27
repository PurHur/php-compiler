# Self-host bootstrap roadmap

**Project north star:** The **compiler fully compiles itself** — native AOT from `lib/` (no `vendor/` at cold boot), then compiles PHP and rebuilds the next compiler revision without Zend. **M2 spine:** **717/717** `require_once` units with real **`bin/vm.php`** (`src/cli_driver.php` via `cli_spine_shim`; `lib/VM/HashTable.php` promoted — [#2543](https://github.com/PurHur/php-compiler/issues/2543)) ([#2134](https://github.com/PurHur/php-compiler/issues/2134); `php script/bootstrap-spine-count.php`). **Living tracker:** [#1492](https://github.com/PurHur/php-compiler/issues/1492) (was [#1056](https://github.com/PurHur/php-compiler/issues/1056)) · **re-root doc:** [self-host-target.md](self-host-target.md) · public status: [development-status § North star](https://purhur.github.io/php-compiler/development-status.html#north-star-self-host). Parent tracking: [#78](https://github.com/PurHur/php-compiler/issues/78) (roadmap), [#212](https://github.com/PurHur/php-compiler/issues/212) (closed umbrella).

## Current gates

| Gate | Command | Status |
|------|---------|--------|
| Phase A inventory | `make bootstrap-inventory-check` | ✅ vm.php-path inventory; **0** source blockers; M2 ratio SSOT: `Phase A inventory files` row in `docs/bootstrap-inventory.md` (no ratio-deferred paths — [#2543](https://github.com/PurHur/php-compiler/issues/2543)); `lib/JIT/Builtin/StringPregMatch.php` and `lib/AOT/Linker.php` **included** in spine |
| Spine PHPCfg parse | `php script/bootstrap-spine-php-cfg-parse-check.php` (`--minimal` for M0 bundle) | ✅ no unsupported php-cfg expr/stmt on spine ([#2575](https://github.com/PurHur/php-compiler/issues/2575)); `BOOTSTRAP_SPINE_PHPCFG_PARSE_GATE=1` in `ci-fast.sh` |
| Phase B lib AOT lint | `php bin/compile.php -l lib/*.php` (with `script/php-env.sh`) | ✅ **14/14** top-level `lib/*.php` units ([#534](https://github.com/PurHur/php-compiler/pull/534)) |
| Phase B fixture lint | `php script/bootstrap-aot-lint.php` | ✅ **13** procedural targets under `test/bootstrap-aot/` + `examples/000-HelloWorld` |
| Phase C native run | `make bootstrap-aot-link` or `./script/bootstrap-aot-link.sh` | ✅ **71/71** link targets OK |
| Phase D `lib/` link | `make bootstrap-aot-link-lib` or `./script/bootstrap-aot-link-lib.sh` | ✅ `test/bootstrap-aot/lib_opcode/main.php` bundles `lib/OpCode.php` ([#540](https://github.com/PurHur/php-compiler/issues/540)) |
| Bundled `lib/Compiler.php` lint | `./script/bootstrap-selfhost-lint.sh` | ✅ `test/selfhost/compiler_minimal/main.php` + literal `require_once` units toward `bin/vm.php` (no `vendor/`) ([#559](https://github.com/PurHur/php-compiler/issues/559)) |
| Compiler compile smoke lint | `php bin/compile.php -l test/selfhost/compiler_compile_smoke/main.php` | ✅ `compiler_minimal` bundle + literal `require_once` of `test/bootstrap-aot/compiler_smoke.php` (named function CFG) |
| Compiler compile smoke native run | `./script/bootstrap-selfhost-compile-smoke-link.sh` or `make bootstrap-selfhost-compile-smoke` | ✅ `build/selfhost-compile-smoke` prints `compiler_compile_smoke bundle OK` (optional `./script/bootstrap-wave-check.sh --with-compile-smoke`) |
| Compiler driver smoke lint | `php bin/compile.php -l test/selfhost/compiler_driver_smoke/main.php` | ✅ `Compiler.php` + Lint stack + `compile_driver_smoke.php` (class method CFG) |
| Compiler driver smoke native run | `./script/bootstrap-selfhost-compiler-driver-smoke-link.sh` or `BOOTSTRAP_COMPILER_DRIVER_SMOKE=1 make bootstrap-selfhost-compiler-driver-smoke` | ✅ `build/selfhost-compiler-driver-smoke` prints `compiler_driver_smoke bundle OK` ([#2136](https://github.com/PurHur/php-compiler/issues/2136)); `COMPILER_DRIVER_SMOKE_GATE=1` default in `script/ci-defaults.env` — runs in `ci-local.sh` llvm tail when LLVM present ([#2137](https://github.com/PurHur/php-compiler/issues/2137), [#2168](https://github.com/PurHur/php-compiler/issues/2168)); set `COMPILER_DRIVER_SMOKE_GATE=0` to skip |
| Compiler unit probe lint | `php bin/compile.php -l test/selfhost/compiler_unit_probe/main.php` | ✅ `compiler_minimal`-scale bundle + `lib/Compiler.php` ([#2216](https://github.com/PurHur/php-compiler/issues/2216)) |
| Compiler unit probe native link | `./script/bootstrap-selfhost-compiler-unit-probe.sh` or `make bootstrap-selfhost-compiler-unit-probe` | ✅ `build/selfhost-compiler-unit-probe` prints `compiler_unit_probe bundle OK` (link-only by default); `BOOTSTRAP_COMPILER_UNIT_PROBE_GATE=1` default-on in `ci-local.sh` llvm tail ([#2221](https://github.com/PurHur/php-compiler/issues/2221)); set `0` to skip |
| Compiler unit probe native emit | `make bootstrap-selfhost-compiler-unit-probe-strict` or `BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1` + compile-driver env | ✅ `emit_path=native` compiles `compiler_unit_probe_compile.php` via dedicated M3 emit helper ([#2618](https://github.com/PurHur/php-compiler/issues/2618)); partial Zend emit when `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1` without strict |
| JIT unit probe lint | `php bin/compile.php -l test/selfhost/jit_unit_probe/main.php` | ✅ minimal bundle + `lib/JIT.php` ([#2332](https://github.com/PurHur/php-compiler/issues/2332)) |
| JIT unit probe native link | `./script/bootstrap-selfhost-jit-unit-probe.sh` or `make bootstrap-selfhost-jit-unit-probe` | ✅ `build/selfhost-jit-unit-probe` prints `jit_unit_probe bundle OK`; `BOOTSTRAP_JIT_UNIT_PROBE_GATE=1` opt-in in `ci-local.sh` llvm tail ([#2361](https://github.com/PurHur/php-compiler/issues/2361)) |
| VM unit probe lint | `php bin/compile.php -l test/selfhost/vm_unit_probe/main.php` | ✅ `compiler_minimal`-scale bundle + `lib/VM.php` + `vm_unit_probe_run.php` ([#2354](https://github.com/PurHur/php-compiler/issues/2354)) |
| VM unit probe native link | `./script/bootstrap-selfhost-vm-unit-probe.sh` or `make bootstrap-selfhost-vm-unit-probe` | ✅ `build/selfhost-vm-unit-probe` prints `vm_unit_probe bundle OK`; optional `BOOTSTRAP_VM_UNIT_PROBE_EXECUTE=1` runs `vm_unit_probe_run` via native LLVM bridge ([#2619](https://github.com/PurHur/php-compiler/issues/2619)); `BOOTSTRAP_VM_UNIT_PROBE_GATE=1` opt-in in `ci-local.sh` llvm tail ([#2368](https://github.com/PurHur/php-compiler/issues/2368)) |
| Parser unit probe lint | `php bin/compile.php -l test/selfhost/parser_unit_probe/main.php` | ✅ `compiler_minimal`-scale bundle + `lib/Runtime.php` + `parser_unit_probe_parse.php` ([#2409](https://github.com/PurHur/php-compiler/issues/2409)) |
| Parser unit probe native link | `./script/bootstrap-selfhost-parser-unit-probe.sh` or `make bootstrap-selfhost-parser-unit-probe` | ✅ `build/selfhost-parser-unit-probe` prints `parser_unit_probe bundle OK` (link-only; `Runtime::parse` on fixture under Zend via PHPUnit); `BOOTSTRAP_PARSER_UNIT_PROBE_GATE=1` default-on in `ci-local.sh` llvm tail ([#2417](https://github.com/PurHur/php-compiler/issues/2417), [#2419](https://github.com/PurHur/php-compiler/issues/2419)); set `0` to skip |
| PHPTypes unit probe lint | `php bin/compile.php -l test/selfhost/types_unit_probe/main.php` | ✅ minimal bundle via `lib/JIT.php` + `JIT\Builtin\Type` ([#2430](https://github.com/PurHur/php-compiler/issues/2430)) |
| PHPTypes unit probe native link | `./script/bootstrap-selfhost-types-unit-probe.sh` or `make bootstrap-selfhost-types-unit-probe` | ✅ `build/selfhost-types-unit-probe` prints `types_unit_probe bundle OK` (link-only; `PHPTypes\Type` constants + union/intersection `fromTypeDecl` under Zend via PHPUnit); `BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=1` default-on in `ci-local.sh` llvm tail ([#2433](https://github.com/PurHur/php-compiler/issues/2433), [#2436](https://github.com/PurHur/php-compiler/issues/2436)); set `0` to skip |
| Compiler compile smoke AOT echo run | `./script/bootstrap-selfhost-compile-smoke-run.sh` or `make bootstrap-selfhost-compile-smoke-run` | ✅ `build/selfhost-compile-smoke-echo` prints `compiler smoke` from `test/bootstrap-aot/compiler_smoke_standalone.php` (wave 7A; included in `--with-compile-smoke`) |
| Wave gate (lint + probe) | `./script/bootstrap-wave-check.sh` | ✅ selfhost-lint → aot-lint → probe; prints `NEXT_LOWER` |
| Self-host compile probe | `make bootstrap-selfhost-probe` | ✅ `-l` + `-o build/selfhost` ([#816](https://github.com/PurHur/php-compiler/issues/816), [#827](https://github.com/PurHur/php-compiler/issues/827), [#913](https://github.com/PurHur/php-compiler/issues/913)) |
| Self-host probe in full CI | `./script/ci-local.sh` (LLVM tail) | ✅ default-on when LLVM 9 present; `BOOTSTRAP_SELFHOST_PROBE_GATE=0` to skip ([#829](https://github.com/PurHur/php-compiler/issues/829)) |
| Wave gate in full CI | `./script/ci-local.sh` (LLVM tail) | ✅ default-on when LLVM 9 present; `BOOTSTRAP_WAVE_CHECK=0` to skip; `./script/bootstrap-wave-check.sh --fail-fast` |
| Self-host native link | `./script/bootstrap-selfhost-link.sh` | ✅ `build/selfhost` prints `compiler_minimal bundle OK` ([#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913)) |
| M2 lib spine smoke lint | `php bin/compile.php -l test/selfhost/compiler_lib_spine_smoke/main.php` | ✅ **717/717** units ([#1492](https://github.com/PurHur/php-compiler/issues/1492), [#2543](https://github.com/PurHur/php-compiler/issues/2543), [#2652](https://github.com/PurHur/php-compiler/issues/2652), [#2001](https://github.com/PurHur/php-compiler/issues/2001), [#2126](https://github.com/PurHur/php-compiler/issues/2126))
| M2 spine coverage drift | `php script/check-selfhost-spine-coverage-sync.php` | ✅ **717** spine units vs **718** Phase A SSOT (no ratio-deferred — [#2543](https://github.com/PurHur/php-compiler/issues/2543)); `SELFHOST_SPINE_COVERAGE_SYNC_GATE=1` ([#1945](https://github.com/PurHur/php-compiler/issues/1945), [#2632](https://github.com/PurHur/php-compiler/issues/2632), [#2652](https://github.com/PurHur/php-compiler/issues/2652))
| M2 lib spine smoke native run | `./script/bootstrap-selfhost-lib-spine-smoke-link.sh` or `BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke` | ✅ `build/selfhost-lib-spine-smoke` prints `compiler_lib_spine_smoke bundle OK` |
| M2 lib spine VM `-r` smoke | `./script/bootstrap-selfhost-lib-spine-vm-smoke.sh` or `BOOTSTRAP_LIB_SPINE_VM_SMOKE=1 make bootstrap-selfhost-lib-spine-vm-smoke` | ✅ same binary + `PHP_COMPILER_VM_SPINE_SMOKE=1` prints `vm-spine-ok` ([#1846](https://github.com/PurHur/php-compiler/issues/1846); `ci-local.sh` default `BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE=1` ([#1867](https://github.com/PurHur/php-compiler/issues/1867)); set `0` to skip) |
| M2 VM driver execute | `./script/bootstrap-selfhost-vm-driver-execute-probe.sh` or `make bootstrap-selfhost-vm-driver-execute-probe` | ✅ spine binary + `PHP_COMPILER_VM_DRIVER_EXECUTE=1` dispatches `bin/vm.php` `run()` → `vm driver ok` ([#2201](https://github.com/PurHur/php-compiler/issues/2201)); `BOOTSTRAP_VM_DRIVER_EXECUTE_GATE=1` opt-in in `ci-local.sh` llvm tail ([#2227](https://github.com/PurHur/php-compiler/issues/2227)) |
| M3 HelloWorld self-host probe lint | `php bin/compile.php -l test/selfhost/compiler_helloworld_smoke/main.php` | ✅ `compiler_compile_smoke` spine + `helloworld_compile_smoke` driver (linkable) |
| M3 HelloWorld compile driver lint | `php bin/compile.php -l test/selfhost/compiler_helloworld_smoke/compile_driver.php` | ✅ `Runtime::parseAndCompile` + mode-file dispatch (native link opt-in) |
| M3 HelloWorld self-host probe | `./script/bootstrap-selfhost-helloworld-probe.sh` or `make bootstrap-selfhost-helloworld` | 🚧 **partial** — bundle link ✅; HelloWorld AOT **run** native ✅; native emit via `helloworld_m3_emit_native_entry.php` blocked at emit-TU runtime init ([#1768](https://github.com/PurHur/php-compiler/issues/1768), [#1937](https://github.com/PurHur/php-compiler/issues/1937)); `emit_path=native` when `compile_smoke_m3_emit: compile OK`; Zend fallback otherwise — see [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) |
| M3 HelloWorld wave gate | `./script/bootstrap-wave-check.sh --with-helloworld` | ✅ opt-in `BOOTSTRAP_M3_HELLOWORLD=1` |
| M3 HelloWorld strict CI gate | `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1 ./script/ci-local.sh` | opt-in ([#1526](https://github.com/PurHur/php-compiler/issues/1526)); runs `bootstrap-selfhost-helloworld-probe.sh` with `BOOTSTRAP_M3_HELLOWORLD_STRICT=1` + compile-driver link (default **off** until `emit_path=native` stable) |
| M5 CLI driver native emit | `make bootstrap-selfhost-cli-driver-emit` or `./script/bootstrap-selfhost-cli-driver-emit.sh` | ✅ `bin/vm.php` + `src/cli_driver.php` via M3 link-time sidecars ([#2699](https://github.com/PurHur/php-compiler/issues/2699)); requires `make bootstrap-selfhost-helloworld-compile-bin` |
| M3 compile-smoke self-host probe | `./script/bootstrap-selfhost-compile-smoke-probe.sh` | 🚧 **partial** — bundle link ✅; compile-smoke AOT **run** native ✅; native emit via `compile_smoke_m3_emit_native_entry.php` blocked at emit-TU runtime init ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); `emit_path=native` when `compile_smoke_m3_emit: compile OK`; Zend fallback (`emit_path=zend partial`) otherwise — see [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) |
| M3 compile-smoke probe CI gate | `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1 ./script/ci-local.sh` | default-on ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); runs `bootstrap-selfhost-compile-smoke-probe.sh` (partial Zend emit + native run) |
| M3 compile-smoke strict probe | `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1 ./script/bootstrap-selfhost-compile-smoke-probe.sh` or `make bootstrap-selfhost-compile-smoke-strict` | ✅ `emit_path=native` — strict auto-enables compile-driver link env ([#2610](https://github.com/PurHur/php-compiler/issues/2610); refuses `emit_path=zend_fallback_would_be_used`) |
| M3 compile-smoke strict CI gate | `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=1 ./script/ci-local.sh` | opt-in ([#1937](https://github.com/PurHur/php-compiler/issues/1937)); runs strict probe (default **off** in `ci-local.sh` until stable) |
| M3 Runtime compile smoke probe | `./script/bootstrap-selfhost-runtime-compile-smoke.sh` or `make bootstrap-selfhost-runtime-compile-smoke` | 🚧 **partial** — bundle link ✅; runtime trivial AOT **run** native ✅; native emit via `runtime_m3_emit_native_entry.php` blocked at emit-TU runtime init ([#2294](https://github.com/PurHur/php-compiler/issues/2294)); `emit_path=native` when `runtime_compile_smoke_m3_emit: compile OK`; Zend fallback (`emit_path=zend partial`) otherwise — see [bootstrap-m5-fast-path.md](bootstrap-m5-fast-path.md) |
| M3 Runtime compile smoke probe CI gate | `BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE=1 ./script/ci-local.sh` | default-on ([#2294](https://github.com/PurHur/php-compiler/issues/2294)); runs `bootstrap-selfhost-runtime-compile-smoke.sh` (partial Zend emit + native run) |
| M3 Runtime compile smoke strict probe | `BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT=1 ./script/bootstrap-selfhost-runtime-compile-smoke.sh` or `make bootstrap-selfhost-runtime-compile-smoke-strict` | ✅ `emit_path=native` — strict auto-enables compile-driver link env ([#2610](https://github.com/PurHur/php-compiler/issues/2610); refuses `emit_path=zend_fallback_would_be_used`) |
| M3 Runtime compile smoke strict CI gate | `BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE=1 ./script/ci-local.sh` | opt-in ([#2294](https://github.com/PurHur/php-compiler/issues/2294)); runs strict probe (default **off** in `ci-local.sh` until stable) |
| M4 bootstrap-loop gen-1 link | `./script/bootstrap-loop-gen1-link.sh` or `make bootstrap-loop-gen1-link` | ✅ gen-1 link + gen-2 native emit (`emit_path=native`) when LLVM present — script defaults compile-driver link env ([#2611](https://github.com/PurHur/php-compiler/issues/2611)); Zend fallback when native blocked; set `BOOTSTRAP_M4_LINK_COMPILE_DRIVER=0` to bisect |
| M4 gen-2 strict emit gate | `BOOTSTRAP_M4_GEN2_STRICT=1 ./script/bootstrap-loop-gen1-link.sh` | opt-in ([#2115](https://github.com/PurHur/php-compiler/issues/2115)); refuses Zend fallback (`emit_path=zend_fallback_would_be_used`); auto-enables compile-driver link env |
| M4 bootstrap-loop probe | `./script/bootstrap-loop-probe.sh` or `make bootstrap-loop-probe` | 🚧 **ladder** — M2 + **M3 HelloWorld strict** (same env as `make bootstrap-selfhost-helloworld`, #2612) + gen-1/gen-2 slice — full mode exit **2** when gen-2 native emit blocked ([#1498](https://github.com/PurHur/php-compiler/issues/1498)) |
| M4 bootstrap-loop probe in CI (fast) | `BOOTSTRAP_LOOP_PROBE_GATE=1 ./script/ci-fast.sh` | opt-in runs `--dry-run` ladder ([#1777](https://github.com/PurHur/php-compiler/issues/1777), [#1929](https://github.com/PurHur/php-compiler/issues/1929)) |
| M4 bootstrap-loop probe in CI (local) | `BOOTSTRAP_M4_LOOP_PROBE=1 ./script/ci-local.sh` | opt-in after M3 strict gates in LLVM tail ([#2058](https://github.com/PurHur/php-compiler/issues/2058)); default off until M3 strict stable |
| Self-host presenter in fast CI | `./script/ci-fast.sh` (default) or `make north-star2-verify` | ✅ default-on in ci-fast ([#1928](https://github.com/PurHur/php-compiler/issues/1928), [#2051](https://github.com/PurHur/php-compiler/issues/2051)); opt-out `NORTH_STAR2_VERIFY_GATE=0` |
| M3 unit probe presenter | `make north-star3-verify` or `./script/north-star3-verify.sh` | ✅ 008 VM + compiler/JIT/VM/parser/PHPTypes unit probes when scripts exist ([#2360](https://github.com/PurHur/php-compiler/issues/2360)); probes [#2216](https://github.com/PurHur/php-compiler/issues/2216), [#2332](https://github.com/PurHur/php-compiler/issues/2332), [#2354](https://github.com/PurHur/php-compiler/issues/2354), [#2418](https://github.com/PurHur/php-compiler/issues/2418), [#2434](https://github.com/PurHur/php-compiler/issues/2434); opt-in CI [#2396](https://github.com/PurHur/php-compiler/issues/2396) |
| M4 strict loop presenter | `make north-star4-verify` or `./script/north-star4-verify.sh` | ✅ inventory + M3 strict + gen-1 link + loop probe ([#2379](https://github.com/PurHur/php-compiler/issues/2379)); exits 0 on partial M4 (`--dry-run-only` or documented probe exit 2); `--strict` for hard fail; opt-in CI [#2429](https://github.com/PurHur/php-compiler/issues/2429) |
| Self-host gate ladder CLI | `./phpc doctor --selfhost` | ✅ M2/M3/M4 env vars + probe commands only ([#2053](https://github.com/PurHur/php-compiler/issues/2053)) |
| Bootstrap iteration CLI | `./phpc test --bootstrap` or `./script/bootstrap-test-subset.sh` | ✅ inventory `--check` + spine count sync (no LLVM link by default); `BOOTSTRAP_TEST_SUBSET_VM_SMOKE=1` for M2 VM spine smoke; `--strict` / `--bootstrap-strict` runs M3 HelloWorld strict probe ([#1961](https://github.com/PurHur/php-compiler/issues/1961)) |

Regenerate: `make bootstrap-profile` (inventory + profile + optional `bootstrap-aot-lint`). Phase C: `make bootstrap-aot-link` (or `php script/bootstrap-aot-lint.php --link`). Phase D: `make bootstrap-aot-link-lib`. Bundled compiler lint: `./script/bootstrap-selfhost-lint.sh`. Live lowering target: `make bootstrap-selfhost-probe` (or `./script/bootstrap-selfhost-compile-probe.sh`; optional `--update-inventory`).

### When to regenerate `docs/bootstrap-inventory.md` ([#830](https://github.com/PurHur/php-compiler/issues/830))

| Change | Command |
|--------|---------|
| New file on `bin/vm.php` path | `make bootstrap-inventory-regenerate` (or `php script/bootstrap-inventory.php`) |
| Self-host probe finds new blocker (`NEXT_LOWER`) | `php script/bootstrap-selfhost-compile-probe.php --update-inventory` then `make bootstrap-inventory-regenerate` if headers drift |
| Capability / bootstrap cross-links | `make bootstrap-profile` |

On harness/Docker-only hosts without `php`, use:

```bash
./script/bootstrap-inventory-regenerate-docker.sh
```

CI enforces freshness via `make bootstrap-inventory-check` (or `php script/bootstrap-inventory.php --check`) in `script/ci-common.sh` ([#765](https://github.com/PurHur/php-compiler/issues/765)). Do not hand-edit inventory tables.

**Static compile lint sweep** (same file list as inventory; [#2208](https://github.com/PurHur/php-compiler/issues/2208)):

```bash
./phpc lint --bootstrap-inventory          # file → unsupported kinds (human report)
./phpc lint --bootstrap-inventory --check  # exit 1 if any inventory file fails lint
```

Drift guard (opt-in CI, [#2210](https://github.com/PurHur/php-compiler/issues/2210)): committed snapshot at `docs/bootstrap-inventory-lint-snapshot.json`. After inventory or linter changes affecting the report:

```bash
php script/bootstrap-inventory-lint-snapshot.php --write
BOOTSTRAP_INVENTORY_LINT_SYNC_GATE=1 ./script/ci-fast.sh
```

**Presenter ladder** ([#2228](https://github.com/PurHur/php-compiler/issues/2228)): one-screen status without running the full sweep:

```bash
./phpc doctor --gates | grep -i bootstrap_inventory
./phpc doctor --selfhost   # also lists doctor --gates probe (#2228)
```

Rows include `BOOTSTRAP_INVENTORY_LINT_SYNC_GATE` (default from `script/ci-defaults.env`), committed snapshot summary (`docs/bootstrap-inventory-lint-snapshot.json`), and copy-paste commands for `phpc lint --bootstrap-inventory` and `php script/check-bootstrap-inventory-lint-sync.php`.

**CFG gap triage** ([#2254](https://github.com/PurHur/php-compiler/issues/2254)): rank unsupported kinds across inventory files:

```bash
php script/bootstrap-inventory-triage.php
php script/bootstrap-inventory-triage.php --json --top 50
```

Drift guard (opt-in CI, [#2265](https://github.com/PurHur/php-compiler/issues/2265)): committed top-50 at `docs/bootstrap-inventory-triage-top50.json`. After inventory lint or triage ranking changes:

```bash
php script/bootstrap-inventory-triage.php --json --top 50 > docs/bootstrap-inventory-triage-top50.json
BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE=1 ./script/ci-fast.sh
```

### When to regenerate `docs/bootstrap-vendor-inventory.md` ([#2030](https://github.com/PurHur/php-compiler/issues/2030))

| Change | Command |
|--------|---------|
| `composer.lock` / `vendor/` bump on M5 packages | `php script/bootstrap-vendor-inventory.php` |

CI: `./script/ci-fast.sh` runs `--check` by default (`BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE=1`, [#2040](https://github.com/PurHur/php-compiler/issues/2040)). Opt out with `BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE=0` during vendor-only iteration. See [`bootstrap-vendor-inventory.md`](bootstrap-vendor-inventory.md).

**Docker** (optional; LLVM 9 in `php-compiler:22.04-dev` — see README):

```console
./script/docker-exec.sh -- bash -lc 'make bootstrap-selfhost-probe && ./script/bootstrap-selfhost-link.sh'
```

Self-host native link requires `PHP_COMPILER_SELFHOST_AOT=1` (set by `./script/bootstrap-selfhost-link.sh` and `make bootstrap-selfhost-probe`). `PHP_COMPILER_JIT_PROGRESS_FILE` is optional progress logging for segfault triage only — it does not enable JIT stubs.

## Self-host `JIT\Result` / FFI policy

Native self-host bundles include `lib/JIT/Result.php` for type closure only. When `PHP_COMPILER_SELFHOST_AOT=1`, `lib/JIT.php` stubs every `\JIT\Result::` method body (LLVM must not lower `FFI::new` / `FFI::memcpy` in `getCallable`), and `Result::getFunc` / `getHandler` / `getCallable` return no-op `Func\JIT` handlers at runtime instead of casting native addresses. Normal JIT/AOT (without the env flag) keeps the real FFI path unchanged.

## Self-host stdlib builtin policy

`lib/JIT/SelfHostBuiltinPolicy.php` centralizes stdlib `Internal` real lowering vs `ExternalMethod` null stubs when `PHP_COMPILER_SELFHOST_AOT=1`.

| Category | Real lowering (`isRequiredForBundle`) | Self-host AOT default |
|----------|---------------------------------------|------------------------|
| filesystem | `dirname`, `basename`, `file_exists`, `is_file`, `is_dir`, `is_readable`, `is_writable`, `file_get_contents`, `realpath` | required |
| string | `strtolower`, `strtoupper`, `strcmp`, `strncmp`, `strcasecmp`, `strncasecmp`, `strlen`, `count`/`sizeof` | required |
| hash | `hash`, `hash_hmac` | required |
| preg | `preg_match`, `preg_quote` | required |
| filter | `filter_var`, `filter_input` | required |
| json | `json_encode` (minimal) | required |
| echo/print | opcode lowering in `lib/JIT.php` | n/a |
| other stdlib | — | `ExternalMethod` stub when not required |

Audit: `php script/audit-stdlib-jit.php`. Auto-stub batch: **10** builtins (bootstrap ops promoted to real lowering).

## Blockers to compile `lib/Compiler.php` (priority order)

1. **Namespaces** ([#84](https://github.com/PurHur/php-compiler/issues/84)) — every `lib/` unit uses `namespace PHPCompiler;` (per-file and bundled minimal subset `-l` pass; native link/run pending)
2. **Class methods** ([#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145)) — inventory warns on `Op\Stmt\ClassMethod` across `lib/`
3. **Nullable typed properties** — `?Type` on fields with `= null` defaults ✅ (`php-types-fromvalue-null.patch`, `test/bootstrap-aot/class_nullable_property.php`); nullable **parameters** ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/nullable_types.php`); nullable **return types** in `Type::fromTypeDecl()` ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/ns_func.php`, `test/bootstrap-aot/ns_nullable_return.php` lint)
4. **Try/catch** ([#57](https://github.com/PurHur/php-compiler/issues/57)) — `lib/Runtime.php`, error paths (`throw` terminal link ✅ [#538](https://github.com/PurHur/php-compiler/pull/538); happy-path try link ✅ [#558](https://github.com/PurHur/php-compiler/issues/558); catch/unwind VM pending)
5. **LLVM linker** — `lib/AOT/Linker.php` uses `shell_exec` (excluded from profile; keep external `clang` for now)
6. **Generators** — `lib/VM/HashTable.php` `iterate`/`iterateKeyed` use eager `ArrayIterator` for bootstrap AOT (no `yield`)

## Bootstrap AOT lint ladder

Add scripts under `test/bootstrap-aot/*.php` — picked up automatically by `script/bootstrap-profile.php` ([#514](https://github.com/PurHur/php-compiler/issues/514)). Multi-file `require_once` chains: `test/bootstrap-aot/<name>/main.php` (helpers alongside; issue [#120](https://github.com/PurHur/php-compiler/issues/120)):

- `echo_hello.php` — baseline procedural
- `compiler_smoke.php` — named function + echo (Compiler CFG smoke for self-host bundle)
- `nullable_types.php` — `?string` parameters (self-host typing)
- `namespace_hello.php` — single-file `namespace` + unqualified calls ([#513](https://github.com/PurHur/php-compiler/issues/513), [#84](https://github.com/PurHur/php-compiler/issues/84))
- `ns_func.php` — namespaced function + global builtin `dirname()` via `NsFuncCall` + `?string` return
- `ns_nullable_return.php` — namespaced `?string` return type resolution (lint-only; link JIT pending)
- `minimal_class.php` — one public method (ClassMethod lowering)
- `class_nullable_property.php` — nullable property with `= null` default
- `class_constants.php` — class `Const_` declarations; Phase C link ✅ ([#520](https://github.com/PurHur/php-compiler/issues/520), [#536](https://github.com/PurHur/php-compiler/pull/536))
- `class_const_fetch.php` — `ClassName::CONST` fetch; Phase C link ✅ ([#545](https://github.com/PurHur/php-compiler/pull/545))
- `instanceof_check.php` — `instanceof` expression; Phase C link ✅ ([#545](https://github.com/PurHur/php-compiler/pull/545))
- `throw_logic.php` — `throw` terminal; Phase C link ✅ ([#538](https://github.com/PurHur/php-compiler/pull/538))
- `require_chain/main.php` — `require_once` helper with shared functions; Phase C link ✅ ([#538](https://github.com/PurHur/php-compiler/pull/538))
- `try_catch.php` — try/catch CFG; Phase C link ✅ ([#558](https://github.com/PurHur/php-compiler/issues/558))
- `cast_int.php` — `(int)` cast on string/float literals (`TYPE_CAST_INT`); Phase C link ✅ ([#868](https://github.com/PurHur/php-compiler/issues/868))
- `cast_string.php` — `(string)` cast on superglobal/scalar (`TYPE_CAST_STRING`); VM + JIT/AOT; MiniWebApp `index.php` dispatch
- `isset_array_offset.php` — `isset($a['k'])` + `var_export()` on hashtable string keys; Phase C link ✅ ([#868](https://github.com/PurHur/php-compiler/issues/868))
- `nested_array_dim.php` — chained `$a['outer']['inner']` on nested array values; Phase C link ([#827](https://github.com/PurHur/php-compiler/issues/827))
- `lib_opcode/main.php` — `require_once lib/OpCode.php`; Phase D link ✅ ([#540](https://github.com/PurHur/php-compiler/issues/540))

Per-file `php bin/compile.php -l lib/*.php` passes for all 14 top-level units after class-const and throw lowering ([#520](https://github.com/PurHur/php-compiler/issues/520), [#529](https://github.com/PurHur/php-compiler/issues/529)). **Bundled** minimal compiler closure: `test/selfhost/compiler_minimal/main.php` (gate: `./script/bootstrap-selfhost-lint.sh`). **Compile smoke** entry bundles the same spine plus `test/bootstrap-aot/compiler_smoke.php`: `test/selfhost/compiler_compile_smoke/main.php` (`php bin/compile.php -l`). **Compile smoke** entry bundles the same spine plus `test/bootstrap-aot/compiler_smoke.php`: `test/selfhost/compiler_compile_smoke/main.php` (`php bin/compile.php -l`).

### `compiler_minimal` bundle (literal `require_once`)

Incremental growth toward `bin/vm.php` inventory path ([#559](https://github.com/PurHur/php-compiler/issues/559)). Regenerate inventory: `make bootstrap-inventory-regenerate` (or `php script/bootstrap-inventory.php`).

| File | Role |
|------|------|
| `lib/OpCode.php`, `lib/Block.php`, `lib/Frame.php`, `lib/Func.php`, `lib/Func/PHP.php` | CFG / call graph |
| `lib/Runtime.php` | compile + run entry |
| `lib/Web/ConstStringFolder.php`, `lib/Web/IncludePathResolver.php`, `lib/Web/LiteralIncludeDiscovery.php` | literal include discovery for `-l` bundle |
| `lib/Web/DeployRoot.php`, `lib/Web/SourceBundler.php` | AOT bundle path + concat (`bin/compile.php` closure) |
| `lib/Module.php`, `lib/ModuleAbstract.php` | extension module interface + shared abstract base |
| `lib/VM.php`, `lib/VM/ClassProperty.php`, `lib/VM/ScriptExit.php`, `lib/VM/Variable.php` | interpreter + value cells toward vm echo path |
| `lib/VM/Refcount.php`, `lib/VM/ErrorReporter.php`, `lib/VM/ScriptStack.php`, `lib/VM/HashTable.php` | hashtable refcount + VM context stack |
| `lib/VM/ClassEntry.php`, `lib/VM/ObjectEntry.php`, `lib/VM/TypeCheck.php` | classes/objects + typed slots (`match`→`switch` in `typeName`) |
| `lib/VM/Optimizer/AssignOp.php`, `lib/VM/Optimizer.php`, `lib/VM/Context.php` | `Runtime` assign-op resolver + `vmContext` |
| `lib/JIT/OperandName.php`, `lib/Printer.php`, `lib/OpCodeNames.php` | opcode helpers (names + debug print) |
| `lib/Handler.php`, `lib/Func/Internal.php`, `lib/Func/JIT.php`, `lib/JIT/Call.php`, `lib/JIT/Builtin.php`, `lib/JIT/Result.php`, `lib/JIT/Variable.php`, `lib/JIT/IssetHelper.php`, `lib/JIT/Scope.php` | Func/JIT spine toward `Runtime::loadJit()` |
| `lib/Web/Superglobals.php` | CGI superglobals (`bin/vm.php`); no `array_map` callable callbacks (foreach subset; issue #1154) |
| `lib/JIT/IteratorHelper.php`, `lib/JIT/JitStringCompare.php`, `lib/JIT/JitValueCompare.php`, `lib/JIT/StringOffsetHelper.php`, `lib/JIT/ValueEchoHelper.php`, `lib/JIT/ScriptMagic.php` | JIT string/value compare, offset dim, echo lowering, script magic constants |
| `lib/JIT/Builtin/Refcount.php`, `lib/JIT/Builtin/Output.php`, `lib/JIT/Builtin/ErrorHandler.php`, `lib/JIT/Builtin/ScriptExit.php`, `lib/JIT/Builtin/IsNullFn.php`, `lib/JIT/Builtin/PendingHeaders.php`, `lib/JIT/Builtin/HttpResponseCode.php`, `lib/JIT/Builtin/SessionId.php`, `lib/JIT/Builtin/SessionName.php`, `lib/JIT/Builtin/StringJsonEncode.php`, `lib/JIT/Builtin/StringGetenv.php` | refcount IR, printf/sprintf, error handler stub, exit/die, is_null IR, pending HTTP headers, response code, session id/name, json_encode/getenv compile helpers |
| `lib/VM/OutputBuffer.php` | request-scoped echo buffering (`VM` echo path) |
| `lib/Compiler.php` | CFG → opcodes |
| `lib/Lint/Issue.php`, `lib/Lint/UnsupportedRegistry.php`, `lib/Lint/LintCompiler.php`, `lib/Lint/Linter.php` | CFG lint spine (`LintCompiler` extends `Compiler`; no closures in bundle) |

**Next toward `bin/compile.php` / Compiler CFG** (`php script/bootstrap-selfhost-next-includes.php`): literal vm.php spine closed for `compiler_minimal` at **109** units; M2 growth bundle `test/selfhost/compiler_lib_spine_smoke/main.php` at **136** units (+**28** lib/ + ext/standard). Driver smoke: `test/selfhost/compiler_driver_smoke/main.php`. README milestone ladder: [#1025](https://github.com/PurHur/php-compiler/issues/1025), [#1056](https://github.com/PurHur/php-compiler/issues/1056).

### `compiler_lib_spine_smoke` bundle (M2 growth)

Extends `compiler_minimal` with remaining vm.php-path `lib/` units that pass bundled AOT lint, plus small `ext/standard/Jit*.php` leaf helpers:

| Added unit | Role |
|------------|------|
| `lib/JIT/Builtin/Type.php`, `lib/JIT/Builtin/Type/String_.php` | JIT builtin type hierarchy toward full stdlib lowering |
| `lib/Doctor.php` | compile-time diagnostics helper |
| `lib/Cli/InvokeCwd.php`, `lib/Cli/PhpcBuild.php`, `lib/Cli/PhpcInit.php`, `lib/Cli/PhpcRun.php` | `phpc` CLI spine toward `bin/compile.php` / `phpc run` |
| `bin/vm.php` | Real `require_once` in `compiler_lib_spine_smoke` (#2134); cli argv via `cli_spine_shim.php` |
| `lib/Web/CgiAotDriver.php`, `lib/Web/CgiDriver.php`, `lib/Web/ProjectDeploy.php` | CGI / deploy drivers on vm.php path |
| `ext/standard/JitAddslashes.php`, `JitBase64Encode.php`, `JitBin2hex.php`, `JitChunkSplit.php`, `JitCrc32.php`, `JitExplode.php`, `JitChmod.php`, `JitCopy.php`, `JitDate.php`, `JitImplode.php`, `JitNl2br.php`, `JitPregQuote.php`, `JitQuotemeta.php`, `JitStrRot13.php`, `JitSessionId.php`, `JitSessionName.php`, `ext/standard/Module.php` | stdlib JIT leaf modules toward full inventory |

Gate: `php bin/compile.php -l test/selfhost/compiler_lib_spine_smoke/main.php`. Optional native link: `make bootstrap-selfhost-lib-spine-smoke` or `./script/bootstrap-wave-check.sh --with-lib-spine-smoke` (`BOOTSTRAP_LIB_SPINE_SMOKE=1`).

### `compiler_helloworld_smoke` bundle (M3 probe)

Extends the `compiler_compile_smoke` spine with inline `PHP_COMPILER_M3_*` env dispatch toward `bin/compile.php`:

| Unit | Role |
|------|------|
| `test/bootstrap-aot/helloworld_compile_smoke.php` | Full `Runtime::parseAndCompile` + `standalone` driver |
| `test/selfhost/compiler_helloworld_smoke/main.php` | Linkable bundle (`build/selfhost-helloworld`; stdout `compiler_helloworld_smoke bundle OK`) |
| `test/selfhost/compiler_helloworld_smoke/compile_driver.php` | Native compile driver with env dispatch (`PHP_COMPILER_M3_COMPILE_MODE=compile`, `PHP_COMPILER_M3_SOURCE`, `PHP_COMPILER_M3_OUT`; opt-in link via `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1`) |

Gate: `php bin/compile.php -l test/selfhost/compiler_helloworld_smoke/main.php`. Compile driver lint: `php bin/compile.php -l test/selfhost/compiler_helloworld_smoke/compile_driver.php`. Native probe: `make bootstrap-selfhost-helloworld` or `./script/bootstrap-selfhost-helloworld-probe.sh` (LLVM 9). Strict (no Zend emit fallback): `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 BOOTSTRAP_M3_RUNTIME_COMPILE=1 BOOTSTRAP_M3_HELLOWORLD_STRICT=1 ./script/bootstrap-selfhost-helloworld-probe.sh`. Full CI opt-in: `BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1 ./script/ci-local.sh` ([#1526](https://github.com/PurHur/php-compiler/issues/1526)). Opt-in wave gate: `./script/bootstrap-wave-check.sh --with-helloworld` (`BOOTSTRAP_M3_HELLOWORLD=1`).

**M3 partial (wave 12):** `lib/JIT.php` stubs ConstStringFolder short FUNCDEF names so `build/selfhost-helloworld` links with the driver embedded. `getenv`/`putenv` use real LLVM lowering in selfhost bundles. Probe runs `build/helloworld-aot` natively (stdout `Hello World`). HelloWorld **emit** still uses Zend `bin/compile.php` unless `BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1` native compile succeeds. **Gap to M3 close:** compile driver **link** with `PHP_COMPILER_M3_COMPILE_DRIVER=1` OK (`helloworld_compile_smoke` + `Runtime::parseAndCompile` real lowering — #1402); **runtime** still blocked on Runtime ctor/JIT spine (`docs/bootstrap-m5-fast-path.md`; opt-in `BOOTSTRAP_M3_RUNTIME_COMPILE=1`).

Native link + run of `compiler_minimal` is gated by `./script/bootstrap-selfhost-link.sh` (LLVM 9; stdout `compiler_minimal bundle OK`). Runtime helpers in the bundle (`VM`, `Runtime`, `Block`, …) are JIT-stubbed for verify; `Compiler` hot paths use existing skip patterns ([#579](https://github.com/PurHur/php-compiler/issues/579), [#913](https://github.com/PurHur/php-compiler/issues/913)). Full `lib/` native self-host remains open.

## Wave workflow

Parallel bootstrap waves use **four agents** with disjoint ownership. Each wave ends with `./script/bootstrap-wave-check.sh` (or `--fail-fast` in CI). Do not commit build artifacts (`build/selfhost`, `build/.last-jit-func`, probe scratch files).

| Agent | Owns | Do not touch |
|-------|------|--------------|
| **A — bundle** | `test/selfhost/compiler_minimal/main.php`, literal `require_once` growth, parse fixes in newly bundled `lib/*` | `lib/JIT/Helper.php`, bulk `ext/standard/` |
| **B — compiler / VM** | `lib/Compiler.php`, `lib/Compiler/*`, `lib/VM/*` helpers on the vm.php path | `lib/JIT/Helper.php`, other agents’ open PR files |
| **C — stdlib JIT** | `ext/standard/*.php`, `script/stdlib-jit-batch-apply.php` name lists | `lib/JIT/Helper.php`, bundle entry |
| **D — tooling / docs** | `script/bootstrap-wave-check.sh`, `script/ci-local.sh` (wave-check default-on), `script/ci-fast.sh` (`CI_FAST_BOOTSTRAP=1`), `script/audit-stdlib-jit.php`, `docs/bootstrap-*.md`, inventory regen | runtime hot paths owned by A/B |

**Wave gate order** (same as `script/bootstrap-wave-check.sh`):

1. `./script/bootstrap-selfhost-lint.sh` — bundled `Compiler.php` AOT lint
2. `php script/bootstrap-aot-lint.php` — quick procedural ladder (exit 2 = LLVM skip)
3. `./script/bootstrap-selfhost-compile-probe.sh` — prints `NEXT_LOWER` for the next native blocker

Inventory between waves: `make bootstrap-inventory-regenerate` (or `php script/bootstrap-inventory.php`). Stdlib JIT audit: `php script/audit-stdlib-jit.php` → `docs/stdlib-jit-audit.md`.

## Non-goals (initial bootstrap)

- Compiling `vendor/` (nikic/php-parser, php-llvm, …)
- Self-hosting the LLVM FFI pipeline inside the binary
- Full `bin/compile.php` feature parity in v1 native compiler
