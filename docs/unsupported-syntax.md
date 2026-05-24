# Unsupported syntax (lint)

`phpc lint` and `bin/lint.php` compile PHP through the same CFG pipeline as `phpc run`, but record unsupported nodes instead of throwing opaque `LogicException` messages.

## Usage

```bash
./phpc lint path/to/entry.php
./phpc lint -r 'for ($i = 0; $i < 3; $i++) echo $i;'
./phpc lint --json path/to/entry.php
```

With `--json`, each issue includes `issue` (GitHub issue number from `UnsupportedRegistry`) and `issue_url` (stable tracker URL, e.g. `https://github.com/PurHur/php-compiler/issues/115`) when mapped.

Exit code `0` when the entry (and best-effort `include`/`require` targets with string literals) compiles; `1` when any unsupported construct is found.

## Include / require graph

`phpc lint --project` and `phpc lint --all` follow `include`/`require` when the path is known at lint time:

- a **string literal** operand, or
- **`__DIR__` concatenated with a literal** suffix (for example `require __DIR__ . '/../config.php'`).

Operands that are not foldable (variables, function calls, non-literal concatenation) emit a **stderr warning** (`dynamic include/require (not followed)`) and are not traversed. Runtime include for VM/JIT/AOT is tracked separately ([#54](https://github.com/PurHur/php-compiler/issues/54), [#85](https://github.com/PurHur/php-compiler/issues/85)).

Some constructs (for example `break`/`continue`, `goto`/`label`, `list()` / short-list / keyed-list destructuring (`["a" => $x]`), and prefix/postfix `++`/`--`) are lowered by php-cfg before the compiler sees them; they compile in VM/JIT/AOT like ordinary assign, array fetches, and `TYPE_JUMP` branches ([#1228](https://github.com/PurHur/php-compiler/issues/1228)).

## Known gaps (tracking issues)

| CFG kind | Tracking |
|----------|----------|
| `Expr_Throw` | [#195](https://github.com/PurHur/php-compiler/issues/195) |
| `Stmt_Try`, `Stmt_TryCatch`, `Stmt_Catch`, `Stmt_Finally` | [#57](https://github.com/PurHur/php-compiler/issues/57) (AOT lint lowering; VM unwind follow-up) |
| `Expr_Yield`, `Expr_YieldFrom` | [#167](https://github.com/PurHur/php-compiler/issues/167) |
| `Expr_Closure` | [#72](https://github.com/PurHur/php-compiler/issues/72) |
| `Expr_ArrowFunction` | [#142](https://github.com/PurHur/php-compiler/issues/142) |
| `Expr_New` (non-trivial) | [#136](https://github.com/PurHur/php-compiler/issues/136) |
| Named arguments, traits, enums | [#168](https://github.com/PurHur/php-compiler/issues/168), [#169](https://github.com/PurHur/php-compiler/issues/169) |
| `Expr_MethodCall` | [#58](https://github.com/PurHur/php-compiler/issues/58) |
| `Stmt_ClassMethod` (class body methods) | [#58](https://github.com/PurHur/php-compiler/issues/58) (visibility/ctors: [#145](https://github.com/PurHur/php-compiler/issues/145)) |

The mapping lives in `lib/Lint/UnsupportedRegistry.php`. Compiler gaps are also listed in `docs/bootstrap-inventory.md` (self-host bootstrap).

## Related

- [#236](https://github.com/PurHur/php-compiler/issues/236) — structured lint CLI
- [#48](https://github.com/PurHur/php-compiler/issues/48) — README capability list
- [#176](https://github.com/PurHur/php-compiler/issues/176) — capability matrix
