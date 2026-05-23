# Self-host bootstrap roadmap

North star: compile a **subset** of php-compiler with itself (native AOT), then run `000-HelloWorld` without Zend PHP. Parent tracking: [#212](https://github.com/PurHur/php-compiler/issues/212) (closed milestone); living index: [#78](https://github.com/PurHur/php-compiler/issues/78).

## Current gates

| Gate | Command | Status |
|------|---------|--------|
| Phase A inventory | `php script/bootstrap-inventory.php --check` | ✅ **379** files on `bin/vm.php` path; **14** source blockers (`lib/AOT/Linker.php`, `lib/JIT/Builtin/StringPregMatch.php`, `lib/VM/HashTable.php` — all excluded) |
| Phase B lib AOT lint | `php bin/compile.php -l lib/*.php` (with `script/php-env.sh`) | ✅ **14/14** top-level `lib/*.php` units ([#534](https://github.com/PurHur/php-compiler/pull/534)) |
| Phase B fixture lint | `php script/bootstrap-aot-lint.php` | ✅ **12** procedural targets under `test/bootstrap-aot/` + `examples/000-HelloWorld` |
| Phase C native run | `make bootstrap-aot-link` or `./script/bootstrap-aot-link.sh` | ✅ Link + execute **12** `aot_link_targets` (stdout vs Zend PHP) |
| Phase D `lib/` link | `make bootstrap-aot-link-lib` or `./script/bootstrap-aot-link-lib.sh` | ✅ `test/bootstrap-aot/lib_opcode/main.php` bundles `lib/OpCode.php` ([#540](https://github.com/PurHur/php-compiler/issues/540)) |
| Bundled `lib/Compiler.php` lint | `./script/bootstrap-selfhost-lint.sh` | ✅ `test/selfhost/compiler_minimal/main.php` + **11** literal `require_once` units toward `bin/vm.php` (no `vendor/`) ([#559](https://github.com/PurHur/php-compiler/issues/559)) |
| Self-host compile probe | `make bootstrap-selfhost-probe` | ✅ `-l` + `-o build/selfhost` for `compiler_minimal` ([#816](https://github.com/PurHur/php-compiler/issues/816)) |
| Self-host native link | `./script/bootstrap-selfhost-link.sh` | ✅ **minimal** bundle link + run (`compiler_minimal bundle OK`) ([#557](https://github.com/PurHur/php-compiler/issues/557)) |

Regenerate: `make bootstrap-profile` (inventory + profile + optional `bootstrap-aot-lint`). Phase C: `make bootstrap-aot-link` (or `php script/bootstrap-aot-lint.php --link`). Phase D: `make bootstrap-aot-link-lib`. Bundled compiler lint: `./script/bootstrap-selfhost-lint.sh`. Live lowering target: `make bootstrap-selfhost-probe` (or `./script/bootstrap-selfhost-compile-probe.sh`; optional `--update-inventory`).

**Docker** (optional; LLVM 9 in `php-compiler:22.04-dev` — see README): `docker run --rm -v "$(pwd):/compiler" -w /compiler php-compiler:22.04-dev bash -lc 'make bootstrap-selfhost-probe'`

## Blockers to compile `lib/Compiler.php` (priority order)

1. **Namespaces** ([#84](https://github.com/PurHur/php-compiler/issues/84)) — every `lib/` unit uses `namespace PHPCompiler;` (per-file and bundled minimal subset `-l` pass; native link/run pending)
2. **Class methods** ([#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145)) — inventory warns on `Op\Stmt\ClassMethod` across `lib/`
3. **Nullable typed properties** — `?Type` on fields with `= null` defaults ✅ (`php-types-fromvalue-null.patch`, `test/bootstrap-aot/class_nullable_property.php`); nullable **parameters** ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/nullable_types.php`); nullable **return types** in `Type::fromTypeDecl()` ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/ns_func.php`, `test/bootstrap-aot/ns_nullable_return.php` lint)
4. **Try/catch** ([#57](https://github.com/PurHur/php-compiler/issues/57)) — `lib/Runtime.php`, error paths (`throw` terminal link ✅ [#538](https://github.com/PurHur/php-compiler/pull/538); happy-path try link ✅ [#558](https://github.com/PurHur/php-compiler/issues/558); catch/unwind VM pending)
5. **LLVM linker** — `lib/AOT/Linker.php` uses `shell_exec` (excluded from profile; keep external `clang` for now)
6. **Generators** — `lib/VM/HashTable.php` (excluded)

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
| `lib/Web/ConstStringFolder.php`, `lib/Web/IncludePathResolver.php` | literal include discovery for `-l` bundle |
| `lib/Module.php` | extension module interface (vm.php path) |
| `lib/VM.php` | interpreter loop (next step toward vm echo path) |
| `lib/Compiler.php` | CFG → opcodes |

Native link of the **minimal** bundle is claimed when `./script/bootstrap-selfhost-link.sh` passes (stdout `compiler_minimal bundle OK`). Full `lib/` native self-host remains open.

## Non-goals (initial bootstrap)

- Compiling `vendor/` (nikic/php-parser, php-llvm, …)
- Self-hosting the LLVM FFI pipeline inside the binary
- Full `bin/compile.php` feature parity in v1 native compiler
