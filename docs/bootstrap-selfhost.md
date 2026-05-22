# Self-host bootstrap roadmap

North star: compile a **subset** of php-compiler with itself (native AOT), then run `000-HelloWorld` without Zend PHP. Parent tracking: [#212](https://github.com/PurHur/php-compiler/issues/212) (closed milestone); living index: [#78](https://github.com/PurHur/php-compiler/issues/78).

## Current gates

| Gate | Command | Status |
|------|---------|--------|
| Phase A inventory | `php script/bootstrap-inventory.php --check` | ✅ 299 files on `bin/vm.php` path; **10** source blockers (only `lib/AOT/Linker.php`, `lib/VM/HashTable.php` — both excluded) |
| Phase B AOT lint | `php script/bootstrap-aot-lint.php` | ✅ Procedural targets under `test/bootstrap-aot/` + `examples/000-HelloWorld` |
| Phase C native run | `bin/compile.php -o …` + execute | ❌ Not started |

Regenerate: `make bootstrap-profile` (inventory + profile + optional `bootstrap-aot-lint`).

## Blockers to compile `lib/Compiler.php` (priority order)

1. **Namespaces** ([#84](https://github.com/PurHur/php-compiler/issues/84)) — every `lib/` unit uses `namespace PHPCompiler;`
2. **Class methods** ([#58](https://github.com/PurHur/php-compiler/issues/58), [#145](https://github.com/PurHur/php-compiler/issues/145)) — inventory warns on `Op\Stmt\ClassMethod` across `lib/`
3. **Nullable typed properties** — `?Type` on fields (e.g. `lib/Frame.php`); nullable **parameters** ✅ (`php-types-nullable-return.patch`, `test/bootstrap-aot/nullable_types.php`)
4. **Try/catch** ([#57](https://github.com/PurHur/php-compiler/issues/57)) — `lib/Runtime.php`, error paths
5. **LLVM linker** — `lib/AOT/Linker.php` uses `shell_exec` (excluded from profile; keep external `clang` for now)
6. **Generators** — `lib/VM/HashTable.php` (excluded)

## Bootstrap AOT lint ladder

Add scripts under `test/bootstrap-aot/*.php` (procedural, no classes) — picked up automatically by `script/bootstrap-profile.php`:

- `echo_hello.php` — baseline
- `nullable_types.php` — `?string` parameters (self-host typing)

Next candidates (open issues): typed properties-only fixture, `require_once` chain ([#120](https://github.com/PurHur/php-compiler/issues/120)), single-class smoke after **#58**.

## Non-goals (initial bootstrap)

- Compiling `vendor/` (nikic/php-parser, php-llvm, …)
- Self-hosting the LLVM FFI pipeline inside the binary
- Full `bin/compile.php` feature parity in v1 native compiler
