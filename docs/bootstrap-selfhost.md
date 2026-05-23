# Self-host bootstrap roadmap

North star: compile a **subset** of php-compiler with itself (native AOT), then run `000-HelloWorld` without Zend PHP. Parent tracking: [#212](https://github.com/PurHur/php-compiler/issues/212) (closed milestone); living index: [#78](https://github.com/PurHur/php-compiler/issues/78).

## Current gates

| Gate | Command | Status |
|------|---------|--------|
| Phase A inventory | `php script/bootstrap-inventory.php --check` | ✅ **393** files on `bin/vm.php` path; **12** source blockers (`lib/AOT/Linker.php`, `lib/JIT/Builtin/StringPregMatch.php` — excluded; `lib/VM/HashTable.php` bundled via `ArrayIterator`) |
| Phase B lib AOT lint | `php bin/compile.php -l lib/*.php` (with `script/php-env.sh`) | ✅ **14/14** top-level `lib/*.php` units ([#534](https://github.com/PurHur/php-compiler/pull/534)) |
| Phase B fixture lint | `php script/bootstrap-aot-lint.php` | ✅ **12** procedural targets under `test/bootstrap-aot/` + `examples/000-HelloWorld` |
| Phase C native run | `make bootstrap-aot-link` or `./script/bootstrap-aot-link.sh` | ✅ Link + execute **12** `aot_link_targets` (stdout vs Zend PHP) |
| Phase D `lib/` link | `make bootstrap-aot-link-lib` or `./script/bootstrap-aot-link-lib.sh` | ✅ `test/bootstrap-aot/lib_opcode/main.php` bundles `lib/OpCode.php` ([#540](https://github.com/PurHur/php-compiler/issues/540)) |
| Bundled `lib/Compiler.php` lint | `./script/bootstrap-selfhost-lint.sh` | ✅ `test/selfhost/compiler_minimal/main.php` + literal `require_once` units toward `bin/vm.php` (no `vendor/`) ([#559](https://github.com/PurHur/php-compiler/issues/559)) |
| Wave gate (lint + probe) | `./script/bootstrap-wave-check.sh` | ✅ selfhost-lint → aot-lint → probe; prints `NEXT_LOWER` |
| Self-host compile probe | `make bootstrap-selfhost-probe` | ⚠️ `-l` OK; native `-o` pending (`HashTableHelper` string-key arrays) — best-effort ([#816](https://github.com/PurHur/php-compiler/issues/816)) |
| Self-host probe in full CI | `BOOTSTRAP_SELFHOST_PROBE_GATE=1 ./script/ci-local.sh` | ✅ runs after bootstrap AOT lint when LLVM 9 present ([#829](https://github.com/PurHur/php-compiler/issues/829)); off in `ci-fast.sh` unless env set |
| Self-host native link | `./script/bootstrap-selfhost-link.sh` | ✅ `build/selfhost` prints `compiler_minimal bundle OK` ([#557](https://github.com/PurHur/php-compiler/issues/557), [#913](https://github.com/PurHur/php-compiler/issues/913)) |

Regenerate: `make bootstrap-profile` (inventory + profile + optional `bootstrap-aot-lint`). Phase C: `make bootstrap-aot-link` (or `php script/bootstrap-aot-lint.php --link`). Phase D: `make bootstrap-aot-link-lib`. Bundled compiler lint: `./script/bootstrap-selfhost-lint.sh`. Live lowering target: `make bootstrap-selfhost-probe` (or `./script/bootstrap-selfhost-compile-probe.sh`; optional `--update-inventory`).

**Docker** (optional; LLVM 9 in `php-compiler:22.04-dev` — see README):

```console
docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev bash -lc 'make bootstrap-selfhost-probe && ./script/bootstrap-selfhost-link.sh'
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
| json | `json_encode` (minimal) | required |
| echo/print | opcode lowering in `lib/JIT.php` | n/a |
| other stdlib | — | `ExternalMethod` stub when not required |

Audit: `php script/audit-stdlib-jit.php`. Auto-stub batch: **30** builtins.

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
- `isset_array_offset.php` — `isset($a['k'])` + `var_export()` on hashtable string keys; Phase C link ✅ ([#868](https://github.com/PurHur/php-compiler/issues/868))
- `lib_opcode/main.php` — `require_once lib/OpCode.php`; Phase D link ✅ ([#540](https://github.com/PurHur/php-compiler/issues/540))

Per-file `php bin/compile.php -l lib/*.php` passes for all 14 top-level units after class-const and throw lowering ([#520](https://github.com/PurHur/php-compiler/issues/520), [#529](https://github.com/PurHur/php-compiler/issues/529)). **Bundled** minimal compiler closure: `test/selfhost/compiler_minimal/main.php` (gate: `./script/bootstrap-selfhost-lint.sh`).

### `compiler_minimal` bundle (literal `require_once`)

Incremental growth toward `bin/vm.php` inventory path ([#559](https://github.com/PurHur/php-compiler/issues/559)). Regenerate inventory: `php script/bootstrap-inventory.php`.

| File | Role |
|------|------|
| `lib/OpCode.php`, `lib/Block.php`, `lib/Frame.php`, `lib/Func.php`, `lib/Func/PHP.php` | CFG / call graph |
| `lib/Runtime.php` | compile + run entry |
| `lib/Web/ConstStringFolder.php`, `lib/Web/IncludePathResolver.php`, `lib/Web/LiteralIncludeDiscovery.php` | literal include discovery for `-l` bundle |
| `lib/Web/DeployRoot.php`, `lib/Web/SourceBundler.php` | AOT bundle path + concat (`bin/compile.php` closure) |
| `lib/Module.php` | extension module interface (vm.php path) |
| `lib/VM.php`, `lib/VM/ClassProperty.php`, `lib/VM/ScriptExit.php`, `lib/VM/Variable.php` | interpreter + value cells toward vm echo path |
| `lib/VM/Refcount.php`, `lib/VM/ErrorReporter.php`, `lib/VM/ScriptStack.php`, `lib/VM/HashTable.php` | hashtable refcount + VM context stack |
| `lib/VM/ClassEntry.php`, `lib/VM/ObjectEntry.php`, `lib/VM/TypeCheck.php` | classes/objects + typed slots (`match`→`switch` in `typeName`) |
| `lib/VM/Optimizer/AssignOp.php`, `lib/VM/Optimizer.php`, `lib/VM/Context.php` | `Runtime` assign-op resolver + `vmContext` |
| `lib/JIT/OperandName.php`, `lib/Printer.php`, `lib/OpCodeNames.php` | opcode helpers (names + debug print) |
| `lib/Handler.php`, `lib/Func/Internal.php`, `lib/Func/JIT.php`, `lib/JIT/Call.php`, `lib/JIT/Builtin.php`, `lib/JIT/Result.php`, `lib/JIT/Variable.php`, `lib/JIT/IssetHelper.php`, `lib/JIT/Scope.php` | Func/JIT spine toward `Runtime::loadJit()` |
| `lib/Web/Superglobals.php` | CGI superglobals (`bin/vm.php`); `array_map` uses named static method (no arrow/closure in bundle) |
| `lib/Compiler.php` | CFG → opcodes |

**Next toward `bin/vm.php`** (`php script/bootstrap-selfhost-next-includes.php`): `lib/JIT/Progress.php` (`??=`), `lib/JIT/IncludeHelper.php`, `lib/JIT/CoalesceHelper.php`, `lib/JIT/Call/Native.php`, …

Native link + run of `compiler_minimal` is gated by `./script/bootstrap-selfhost-link.sh` (LLVM 9; stdout `compiler_minimal bundle OK`). Runtime helpers in the bundle (`VM`, `Runtime`, `Block`, …) are JIT-stubbed for verify; `Compiler` hot paths use existing skip patterns ([#579](https://github.com/PurHur/php-compiler/issues/579), [#913](https://github.com/PurHur/php-compiler/issues/913)). Full `lib/` native self-host remains open.

## Wave workflow

Parallel bootstrap waves use **four agents** with disjoint ownership. Each wave ends with `./script/bootstrap-wave-check.sh` (or `--fail-fast` in CI). Do not commit build artifacts (`build/selfhost`, `build/.last-jit-func`, probe scratch files).

| Agent | Owns | Do not touch |
|-------|------|--------------|
| **A — bundle** | `test/selfhost/compiler_minimal/main.php`, literal `require_once` growth, parse fixes in newly bundled `lib/*` | `lib/JIT/Helper.php`, bulk `ext/standard/` |
| **B — compiler / VM** | `lib/Compiler.php`, `lib/Compiler/*`, `lib/VM/*` helpers on the vm.php path | `lib/JIT/Helper.php`, other agents’ open PR files |
| **C — stdlib JIT** | `ext/standard/*.php`, `script/stdlib-jit-batch-apply.php` name lists | `lib/JIT/Helper.php`, bundle entry |
| **D — tooling / docs** | `script/bootstrap-wave-check.sh`, `script/audit-stdlib-jit.php`, `docs/bootstrap-*.md`, inventory regen | runtime hot paths owned by A/B |

**Wave gate order** (same as `script/bootstrap-wave-check.sh`):

1. `./script/bootstrap-selfhost-lint.sh` — bundled `Compiler.php` AOT lint
2. `php script/bootstrap-aot-lint.php` — quick procedural ladder (exit 2 = LLVM skip)
3. `./script/bootstrap-selfhost-compile-probe.sh` — prints `NEXT_LOWER` for the next native blocker

Inventory between waves: `php script/bootstrap-inventory.php`. Stdlib JIT audit: `php script/audit-stdlib-jit.php` → `docs/stdlib-jit-audit.md`.

## Non-goals (initial bootstrap)

- Compiling `vendor/` (nikic/php-parser, php-llvm, …)
- Self-hosting the LLVM FFI pipeline inside the binary
- Full `bin/compile.php` feature parity in v1 native compiler
