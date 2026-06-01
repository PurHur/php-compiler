# Generators (`yield`) — VM, JIT, and AOT path (#167)

## Current state

| Layer | Status | Mechanism |
|-------|--------|-----------|
| **Compiler** | Done | `TYPE_YIELD` / `TYPE_YIELD_FROM`; `Block::$isGenerator` |
| **VM** | Done | `GeneratorState`, `VM::GENERATOR_YIELD`, foreach over generators; keyed yield (#3085); `yield from` array + generator delegation |
| **JIT (`bin/jit.php`)** | MCJIT resume (#3074) | Main script MCJIT when yield only in nested functions; `GeneratorHelper` switch-on-resume-ip; linear `yield`, prefix opcodes before each yield segment, packed-array `yield from`, nested `yield from inner()`, dynamic `yield from $g` |
| **AOT (`phpc build`)** | Nested functions (#3115) | `GeneratorHelper` resume lowering for generator *functions*; script-scope top-level `yield` still blocked (`Runtime::standalone()` guard); AOT fixtures: `generator_yield*.phpt`, `generator_yield_from_*.phpt` |
| **Bootstrap spine AOT** | Nested generators OK | `lib/Block.php` iterator helpers use function-scope `yield` only; inventory flags **script-scope** yield (#2483) |

Compliance PHPT: `test/compliance/GeneratorVMTest.php`, `GeneratorJITTest.php`, `test/fixtures/aot/cases/generator_*.phpt`.

## Compile-time detection (SSOT)

`Block::containsGeneratorOpcodes()` walks nested CFG blocks (including `TYPE_FUNCDEF` bodies) for `TYPE_YIELD` / `TYPE_YIELD_FROM`.

`Block::requiresVmLowering()` uses script-scope generator scan plus try/catch/throw (#2114, #3074).

Used by:

- `bin/jit.php` — skip `$runtime->jit()` when script-scope yield or EH; nested generator bodies use `GeneratorHelper` (#3074)
- `Runtime::standalone()` — fail fast for AOT when script-scope yield is present
- `JIT::compileBlock()` — stub generator function bodies instead of lowering opcodes (legacy path; nested generator calls use `GeneratorHelper` resume)

## Native lowering (implemented)

MCJIT/AOT resume uses a **switch-on-IP state machine** (not LLVM coroutine passes):

1. **Per generator function**: split CFG at each `TYPE_YIELD` into resume segments.
2. **State struct**: spill live locals into a heap frame compatible with `GeneratorState`.
3. **Prologue**: generator call returns a wrapper without entering the body until `next`/`foreach`.
4. **Resume**: restore locals and branch to the post-yield block.
5. **`yield from`**: delegate to inner generator/array iterator; prefix opcodes materialize the container before resume (#2483).

MCJIT: `try` / `catch` / `finally` inside generator bodies lower via `GeneratorHelper` resume prefixes + `TryCatchHelper` (#4069). Script-scope top-level `yield` for AOT/bootstrap remains deferred.

## Related

- [capabilities-syntax.md](capabilities-syntax.md) — matrix row for generators
- [unsupported-syntax.md](unsupported-syntax.md) — `Expr_YieldFrom` no longer lint-tracked (#167 closed)
- `lib/VM/GeneratorState.php`, `lib/VM.php` (`TYPE_YIELD*` cases)
- Wave 4 epic ([#2483](https://github.com/PurHur/php-compiler/issues/2483)): child issues #72, #142, #101, #144, #167 closed; VM/JIT/AOT nested generators + trait adaptations green; script-scope yield and MCJIT closure `use (&$x)` execute remain honestly deferred in `docs/capabilities-syntax.md`
