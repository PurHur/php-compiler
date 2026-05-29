# Generators (`yield`) — VM, JIT fallback, and AOT path (#167)

## Current state

| Layer | Status | Mechanism |
|-------|--------|-----------|
| **Compiler** | Done | `TYPE_YIELD` / `TYPE_YIELD_FROM`; `Block::$isGenerator` |
| **VM** | Done | `GeneratorState`, `VM::GENERATOR_YIELD`, foreach over generators |
| **JIT (`bin/jit.php`)** | MCJIT resume (#3074) | Main script MCJIT when yield only in nested functions; `GeneratorHelper` switch-on-resume-ip |
| **AOT (`phpc build`)** | Native resume (#3115) | Same `GeneratorHelper` as JIT; script-scope `yield` rejected |
| **Bootstrap spine AOT** | Blocked | `script/bootstrap-lib.php` inventory flags `generator yield` |

Compliance PHPT: `test/compliance/GeneratorVMTest.php`, `GeneratorJITTest.php`.

## Compile-time detection (SSOT)

`Block::containsGeneratorOpcodes()` walks nested CFG blocks (including `TYPE_FUNCDEF` bodies) for `TYPE_YIELD` / `TYPE_YIELD_FROM`.

`Block::containsGeneratorOpcodesInScriptScope()` — top-level script only (#3074).

`Block::requiresVmLowering()` — script-scope generators or try/catch/throw (#2114).

Used by:

- `bin/jit.php` — skip MCJIT when `requiresVmLowering()` (script-scope yield or EH)
- `Runtime::standalone()` — reject script-scope `yield`; nested generators use `GeneratorHelper`
- `JIT::compileBlock()` — `GeneratorHelper::compileResumeFunction()` for generator bodies

## Native lowering options

### Option A — Permanent VM fallback (low risk)

Keep MCJIT for non-generator code; generator calls always dispatch to the existing VM (`GeneratorState`, `advanceGeneratorIteration`). Matches today's `bin/jit.php` behavior. Minimal LLVM work; generators stay slower than native loops.

### Option B — LLVM coroutines (high effort, full native)

1. **Per generator function**: split CFG at each `TYPE_YIELD` into coroutine suspend points.
2. **State struct**: spill live locals + `$this` + iterator state into a heap `GeneratorState`-compatible frame (reuse `lib/VM/GeneratorState.php` layout or a native mirror).
3. **Prologue**: `FUNCCALL_EXEC` on generator returns a wrapper object without entering the body.
4. **Resume**: foreach / manual `next` calls a resume intrinsic that restores locals and branches to the post-yield block.
5. **`yield from`**: delegate to inner generator's resume loop (array path can stay eager in VM or lower to iterator glue).

Requires LLVM coroutine passes (or hand-rolled switch-on-IP state machine like many C++20-less compilers). Must interoperate with refcounting and exceptions (#2114) before generators-in-try is safe in JIT.

### Recommended sequence

1. ✅ VM + JIT fallback + compile-time guards (this issue)
2. EH stability in MCJIT (#2114) — share `requiresVmLowering` gate
3. ✅ MCJIT resume lowering for generator *calls* while main script stays native (#3074)
4. Prototype switch-on-IP lowering for single-function generators without `yield from`
5. ✅ AOT link for nested generators (`#3115`); bootstrap inventory blocker remains until spine is green

## Related

- [capabilities-syntax.md](capabilities-syntax.md) — matrix row for generators
- [unsupported-syntax.md](unsupported-syntax.md) — lint mapping for `Expr_YieldFrom`
- `lib/VM/GeneratorState.php`, `lib/VM.php` (`TYPE_YIELD*` cases)
