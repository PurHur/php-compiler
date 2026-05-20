# Unsupported syntax (lint)

`phpc lint` and `bin/lint.php` compile PHP through the same CFG pipeline as `phpc run`, but record unsupported nodes instead of throwing opaque `LogicException` messages.

## Usage

```bash
./phpc lint path/to/entry.php
./phpc lint -r 'for ($i = 0; $i < 3; $i++) echo $i;'
./phpc lint --json path/to/entry.php
```

Exit code `0` when the entry (and best-effort `include`/`require` targets with string literals) compiles; `1` when any unsupported construct is found.

## Known gaps (tracking issues)

| CFG kind | Tracking |
|----------|----------|
| `Stmt_Foreach` | [#53](https://github.com/PurHur/php-compiler/issues/53) |
| `Expr_BinaryOp_Coalesce` (`??`) | [#99](https://github.com/PurHur/php-compiler/issues/99) |
| `Expr_Throw`, `Stmt_Try`, `Stmt_Catch`, `Stmt_Finally` | [#195](https://github.com/PurHur/php-compiler/issues/195) |
| `Expr_Ternary` (`?:`) | [#114](https://github.com/PurHur/php-compiler/issues/114) |
| `Expr_AssignOp_*`, `Expr_BinaryOp_ShiftLeft` / `ShiftRight` (compound assign) | [#136](https://github.com/PurHur/php-compiler/issues/136) |
| `Stmt_Break`, `Stmt_Continue` | [#115](https://github.com/PurHur/php-compiler/issues/115) |
| `Expr_Match` | [#143](https://github.com/PurHur/php-compiler/issues/143) |
| `Expr_Yield`, `Expr_YieldFrom` | [#167](https://github.com/PurHur/php-compiler/issues/167) |
| `Expr_Closure` | [#72](https://github.com/PurHur/php-compiler/issues/72) |
| `Expr_ArrowFunction` | [#142](https://github.com/PurHur/php-compiler/issues/142) |
| `Expr_PreInc`, `Expr_PostInc`, `Expr_PreDec`, `Expr_PostDec` (`++`/`--`) | [#137](https://github.com/PurHur/php-compiler/issues/137) |
| `Expr_New` (non-trivial) | [#136](https://github.com/PurHur/php-compiler/issues/136) |
| Named arguments, traits, enums | [#168](https://github.com/PurHur/php-compiler/issues/168), [#169](https://github.com/PurHur/php-compiler/issues/169) |

The mapping lives in `lib/Lint/UnsupportedRegistry.php`. Compiler gaps are also listed in `docs/bootstrap-inventory.md` (self-host bootstrap).

## Related

- [#236](https://github.com/PurHur/php-compiler/issues/236) — structured lint CLI
- [#48](https://github.com/PurHur/php-compiler/issues/48) — README capability list
- [#176](https://github.com/PurHur/php-compiler/issues/176) — capability matrix
