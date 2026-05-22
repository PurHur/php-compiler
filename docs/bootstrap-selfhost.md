# Self-host bootstrap roadmap

North star: compile a **subset** of php-compiler with itself (native AOT), then run `000-HelloWorld` without Zend PHP. Parent tracking: [#212](https://github.com/PurHur/php-compiler/issues/212) (closed milestone); living index: [#78](https://github.com/PurHur/php-compiler/issues/78).

## Current gates

| Gate | Command | Status |
|------|---------|--------|
| Phase A inventory | `php script/bootstrap-inventory.php --check` | ✅ **300** files on `bin/vm.php` path; **10** source blockers (only `lib/AOT/Linker.php`, `lib/VM/HashTable.php` — both excluded) |
| Phase B lib AOT lint | `php bin/compile.php -l lib/*.php` (with `script/php-env.sh`) | ✅ **14/14** top-level `lib/*.php` units ([#534](https://github.com/PurHur/php-compiler/pull/534)) |
| Phase B fixture lint | `php script/bootstrap-aot-lint.php` | ✅ **11** procedural targets under `test/bootstrap-aot/` + `examples/000-HelloWorld` |
| Phase C native run | `make bootstrap-aot-link` or `./script/bootstrap-aot-link.sh` | ✅ Link + execute **7** `aot_link_targets` (stdout vs Zend PHP) |

Regenerate: `make bootstrap-profile` (inventory + profile + optional `bootstrap-aot-lint`). Phase C: `make bootstrap-aot-link` (or `php script/bootstrap-aot-lint.php --link`).

### Phase C link pending (lint-only today)

These fixtures pass `compile.php -l` but are excluded from `aot_link_targets` in `script/bootstrap-lib.php` until JIT/link gaps close:

- `test/bootstrap-aot/class_const_fetch.php` — `Expr_ClassConstFetch` runtime fetch ([#84](https://github.com/PurHur/php-compiler/issues/84))
- `test/bootstrap-aot/instanceof_check.php` — `instanceof` lowering ([#530](https://github.com/PurHur/php-compiler/issues/530) partial)
- `test/bootstrap-aot/require_chain/main.php` — multi-file `require_once` ([#120](https://github.com/PurHur/php-compiler/issues/120))
- `test/bootstrap-aot/throw_logic.php` — `throw` terminal execution ([#57](https://github.com/PurHur/php-compiler/issues/57))

## Blockers to compile `lib/Compiler.php` (priority order)

1. **Namespaces** ([#84](https://github.com/PurHur/php-compiler/issues/84)) — every `lib/` unit uses `namespace PHPCompiler;` (per-file `-l` passes; bundled self-compile still blocked)
2. **Class methods** ([#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145)) — inventory warns on `Op\Stmt\ClassMethod` across `lib/`
3. **Nullable typed properties** — `?Type` on fields with `= null` defaults ✅ (`php-types-fromvalue-null.patch`, `test/bootstrap-aot/class_nullable_property.php`); nullable **parameters** ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/nullable_types.php`)
4. **Try/catch** ([#57](https://github.com/PurHur/php-compiler/issues/57)) — `lib/Runtime.php`, error paths (`throw` terminal lint ✅; catch paths pending)
5. **LLVM linker** — `lib/AOT/Linker.php` uses `shell_exec` (excluded from profile; keep external `clang` for now)
6. **Generators** — `lib/VM/HashTable.php` (excluded)

## Bootstrap AOT lint ladder

Add scripts under `test/bootstrap-aot/*.php` — picked up automatically by `script/bootstrap-profile.php` ([#514](https://github.com/PurHur/php-compiler/issues/514)). Multi-file `require_once` chains: `test/bootstrap-aot/<name>/main.php` (helpers alongside; issue [#120](https://github.com/PurHur/php-compiler/issues/120)):

- `echo_hello.php` — baseline procedural
- `nullable_types.php` — `?string` parameters (self-host typing)
- `namespace_hello.php` — single-file `namespace` + unqualified calls ([#513](https://github.com/PurHur/php-compiler/issues/513), [#84](https://github.com/PurHur/php-compiler/issues/84))
- `minimal_class.php` — one public method (ClassMethod lowering)
- `class_nullable_property.php` — nullable property with `= null` default
- `class_constants.php` — class `Const_` declarations; Phase C link ✅ ([#520](https://github.com/PurHur/php-compiler/issues/520), [#536](https://github.com/PurHur/php-compiler/pull/536))
- `class_const_fetch.php` — `ClassName::CONST` fetch (lint ✅; link pending)
- `instanceof_check.php` — `instanceof` expression (lint ✅; link pending)
- `throw_logic.php` — `throw` terminal (lint ✅; link pending)
- `require_chain/main.php` — `require_once` helper with shared functions (lint ✅; link pending)

Per-file `php bin/compile.php -l lib/*.php` passes for all 14 top-level units after class-const and throw lowering ([#520](https://github.com/PurHur/php-compiler/issues/520), [#529](https://github.com/PurHur/php-compiler/issues/529)); **bundled** self-compile of the compiler subset remains in progress.

## Non-goals (initial bootstrap)

- Compiling `vendor/` (nikic/php-parser, php-llvm, …)
- Self-hosting the LLVM FFI pipeline inside the binary
- Full `bin/compile.php` feature parity in v1 native compiler
