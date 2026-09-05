# Ownership map

Load-bearing paths, the gate that proves them, and the failure mode they have produced before.
Child of [#36403](https://github.com/PurHur/php-compiler/issues/36403). Budgets: `script/size-budgets.json` + `script/check-size-budgets.sh`.

## How to read this

| column | meaning |
|---|---|
| **owns** | What breaks if this path is wrong |
| **gate** | Cheapest command that must stay green after edits |
| **failure mode** | Documented incident (issue) — do not re-introduce |

Gates are **local/Docker only** — GitHub `CLEAN` means no checks configured (see `AGENTS.md`).

---

## `lib/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `lib/Compiler.php` + `lib/Compiler/Concern/*` | PHP → CFG/opcodes | `php script/opcode-corpus-md5.php --check`; `script/differential-sweep.sh` | Super-quadratic call-arg scans (#36224); Concern extract opcode drift (#36230) |
| `lib/JIT.php` + `lib/JIT/Concern/*` | Opcode → LLVM IR | `./script/aot-smoke.sh`; IR size gate in same script | Silent whole-script VM fallback (#36222); alwaysinline never honoured (#36213). Traits live under `lib/JIT/Concern/` but stay in `namespace PHPCompiler` so relative `ext\` / `JIT\` resolves like the parent class. |
| `lib/VM.php` + `lib/VM/Concern/*` | Interpreter loop | `script/differential-sweep.sh`; targeted `./script/phpunit.sh --filter VMTest` | 1M-loop exit 255 (#36148/#15906); O(scope²) hot loop (#36207). Traits under `lib/VM/Concern/` stay in `namespace PHPCompiler` like JIT Concerns. |
| `lib/AOT/Linker.php` | Native link line | `./script/aot-smoke.sh` (size gate) | Unconditional libsodium/… link (#36200); missing `--gc-sections` (#36198) |
| `lib/AOT/HelperRuntimeCache.php` | Split-TU helper `.o` cache | `php script/check-helper-runtime-prelink.php` | Fingerprint-stale cache silently disabled (#23457); monolithic `.text` vs `common.o` (#36246) |
| `lib/AOT/HelperRuntimeCommon.php` | Shared runtime prologue | same + `PHP_COMPILER_HELPER_RUNTIME_COMMON=1` smoke | Auto-link segfault until gc-section corpus (#36423/#36429) |
| `lib/JIT/Builtin/` | Runtime value model | aot-smoke + differential `--aot --repeat 3` | `__value__` align-1 UB (#36214); packed stride (#36214) |
| `lib/Config.php` | `PHP_COMPILER_*` env | `php script/generate-configuration-docs.php --check` | 204 unregistered getenv knobs (#36201) |
| `lib/ExtensionRegistry.php` + `ext/*/ext.json` | Extension load graph | `php script/sync-extension-manifests.php --check` | lib→ext imports / dual registries (#36204/#23480) |

## `ext/standard`

| path | owns | gate | failure mode |
|---|---|---|---|
| `ext/standard/*.php` + `*JitHelper.php` | Stdlib builtins | differential sweep; capability matrix `--check` | Literal-only `range()` (#36243); stale `compileTimeString` strlen (#36244/#36406) |
| `ext/standard/Module.php` | Registration | `php script/capability-matrix.php --check` | Silent-null methods before registry route (#36202) |

Do **not** add modules to `Runtime::loadCoreModules()` — every binary already links ~75 extensions.

## `script/ci-*` and verify presenters

| path | owns | gate | failure mode |
|---|---|---|---|
| `script/ci-defaults.env` | Memory / Docker caps | `script/check-size-budgets.sh` (export count) | Ceremony flag sprawl (#36211); OOM without 1536M (#497) |
| `script/ci-common.sh` / `ci-fast.sh` / `ci-local.sh` | PR / merge ladders | run the script itself | Gates that pass by absence (#36210/#36248); `\|\| true` swallow (#36209) |
| `script/check-generated-docs.sh` | Doc/inventory drift | itself (< 30 s) | Master red for every clone (#15619/#15621) |
| `script/check-size-budgets.sh` | Line-count ratchet | itself | Monotone growth of Compiler/JIT (#36403) |
| `script/aot-smoke.sh` | Toolchain liveness | itself (8–9/9) | Mass differential “regressions” from dead toolchain (#24194) |
| `script/differential-sweep.sh` | Zend-vs-us output | itself | Silent wrong output missed by compliance (#23354) |
| `script/north-star5-verify.sh` | M5 self-host presenter | `make north-star5-verify-fast` | Sidecar COPY reported as native (#21860/#36146) |
| `script/apply-patches.sh` | Vendor patch apply | `--verify-pristine` in docs gate | structgep / simplifier guard drift (#36143/#36377) |
| `script/bootstrap-inventory.php` | `bin/vm.php` require graph | `--check` | Inventory/spine desync blocks north-star5 |

## `prelinked/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `prelinked/helper-runtime/<arch>/` | Committed helper units + `common.o` | `php script/check-helper-runtime-prelink.php` | 790 MB duplicate runtime in every `unit.o` (#36198/#36246); restamp ≠ rebuild |
| `prelinked/bootstrap-gen0/` | Seed native driver | `php script/bootstrap-gen0-staleness.php`; north-star5-fast | 272 restamps while driver cannot compile hello (#23468/#36145) |
| `prelinked/bootstrap-vendor/` | Vendor prelink sidecars | north-star5-fast / spine sync | Cold-boot without `vendor/` lies |

**Never restamp** a fingerprint without a build that produced the bytes (`artifact-honesty.mdc`).

## `patches/`

| path | owns | gate | failure mode |
|---|---|---|---|
| `patches/*.patch` | Vendor tree shape (php-cfg / php-llvm / php-types) | `script/apply-patches.sh --verify-pristine` | Fictional context → clean checkout unbuildable (#36377); structgep assert skip (#36143) |

Patch source of truth is moving toward forks (#36229); until then every patch needs an idempotent guard in `apply-patches.sh`.

---

## Size ratchet

After a Concern extract that shrinks a budgeted file, lower `budget` in `script/size-budgets.json` to the new line count (never raise it). Targets: Compiler/JIT ≤ 25k (then 20k), VM ≤ 15k, `script/` ≤ 150 top-level files (issue-specific helpers go under `script/composer/`, `script/fuzz/`, `script/lib/`, …), `ci-defaults.env` ≤ 60 exports.

## Related ADRs / docs

- [`docs/adr/README.md`](adr/README.md) — settled + DECISION index (#36402)
- `docs/architecture-review-2026-07.md`, `docs/self-host-target.md`, `docs/bootstrap-m5-fast-path.md`
- `AGENTS.md` (five things that bite), `.cursor/rules/artifact-honesty.mdc`
