# Migrating php-compiler from LLVM 9 to LLVM 22

Written 2026-07-26. Every fact below was probed on this box against a real LLVM 22.1.8 install, not
assumed.

## Established facts

| probe | result |
|---|---|
| LLVM 22.1.8 installs on jammy from `apt.llvm.org/jammy llvm-toolchain-jammy-22` | works |
| `LLVMBuildLoad` / `LLVMBuildCall` / `LLVMBuildGEP` in `Core.h` | **0 occurrences — removed** |
| `LLVMBuildLoad2` / `Call2` / `GEP2` | present; the only option |
| `llvm-c/Transforms/` contents | **only `PassBuilder.h`** — `PassManagerBuilder.h` gone (removed in 17) |
| shared object name | `libLLVM-22.so` — **no `.1` suffix**, unlike `libLLVM-9.so.1` |
| binding generator | `ircmaxell/ffime:dev-master` (dev-master, needs `minimum-stability: dev`) |

Call sites in `lib/` that must supply a type once pointers are opaque:

| call | sites |
|---|---|
| `->load(` | 1,026 |
| `->structGep(` | 797 |
| `->gep(` | 65 |
| `->call(` | 2,226 |
| `getElementType` (disappears entirely) | 20 |

**There is no incremental path.** The old symbols are not present as deprecated shims, so nothing
compiles until pointee types are threaded. That single fact shapes the whole plan.

**The mitigating fact:** every one of those ~4,100 sites goes through `LLVMAbstract\Builder`. The
work belongs in the binding, not in 4,100 edits.

## Binding generation: SOLVED (2026-07-27)

The blocker was recorded as "FFIMe cannot parse `llvm-c/Core.h`". That was three separate problems
wearing one label. All are now cleared, and the bindings generate:

```
GENERATED OK
61035 lines  ->  build/llvmup/llvm22.php   (2.9 MB, php -l clean)
```

Verified present in the output: `LLVMBuildLoad2`, `LLVMBuildCall2`, `LLVMBuildGEP2`,
`LLVMRunPasses`, `LLVMCreatePassBuilderOptions`, `LLVMCreateExecutionEngineForModule`.

### What actually blocked it

1. **FFIMe cannot walk the include graph.** Feed it a `cpp -P` flattened header instead — all
   eleven `llvm-c` headers collapse to 2,354 lines with 840 function declarations.
2. **`ircmaxell/php-c-parser` requires PHP >= 8.4.** The pinned image is 8.2.32, so generation can
   **never** run inside this project's own environment. It is a one-off on a separate PHP; the
   output is committed source, so the compiler's runtime version is unaffected.
3. **The generator host needs `ext-ffi`** (`libffi-dev` + `docker-php-ext-install ffi`).
   `php:8.4-cli` does not ship it enabled.

### Reproducing it

`build/llvmup/gen84c.sh`, run against `php:8.4-cli`:

```bash
docker run --rm -v /root/php-compiler:/app -w /app php:8.4-cli bash /app/build/llvmup/gen84c.sh
```

One detail that is easy to get wrong: strip `__attribute__` with **balanced-paren matching**, not a
regex. `__attribute__((visibility("default")))` defeats `\(\([^)]*\)\)` — the character class
stops at the inner paren and leaves the outer one behind, so every declaration emerges as
`) void LLVMFoo(...)` and the C parser rejects the file. That failure surfaces inside FFIMe and
looks like an FFIMe limitation; it is not.

### What this does and does not unblock

It produces the **bindings**. It does not touch the ~4,100 call sites that must supply a pointee
type, which remains the actual body of the migration and is unchanged by this.

## The de-risking move

> **Do the pointee-type threading on LLVM 9 first.**

Under LLVM 9 the type is still derivable, so threading it through the PHP `Value` wrapper is a
**no-op that can be validated against the currently working compiler**. Only once the differential
sweep and compliance comparison are clean does the LLVM 22 backend get switched on.

That converts one large risky change into two smaller verifiable ones, which matters a great deal
here because there is no CI on `lib/`.

## Phases

### Phase 0 — Prove the binding can be generated (gate)

Generate `llvm22.php` with FFIMe against `/usr/lib/llvm-22/include/`.

**If FFIMe cannot parse LLVM 22 headers**, the generated-binding approach is dead and the fallback is
to hand-write the FFI surface actually used. Measure it first — the codebase calls a few hundred
distinct `LLVM*` functions, not the whole C API, so a hand-written surface is finite.

Do not start Phase 1 until this gate is answered.

### Phase 1 — Pointee-type threading (on LLVM 9, no behaviour change)

Add a `pointeeType` to the PHP `Value` wrapper and record it wherever a pointer is produced:
`alloca`, `gep`/`structGep`, module globals, `bitcast`/`pointerCast`, call returns, function
parameters. Then `load`/`gep`/`structGep`/`call` read it instead of asking LLVM.

Acceptance: differential sweep green (VM **and** AOT), compliance set-difference empty vs master,
benchmark table unchanged. Still on LLVM 9 throughout — any difference is a bug in the threading.

### Phase 2 — LLVM22 implementation

`Chooser` already dispatches across `LLVM4/7/8/9`, so this is **additive and reversible**:

* new `lib/LLVM22/` implementing the same interfaces;
* `Chooser::FILES` gains `libLLVM-22.so` — note the missing `.1`, the current map would never
  match it;
* map `load → LLVMBuildLoad2`, `call → LLVMBuildCall2`, `gep → LLVMBuildGEP2`,
  `structGep → LLVMBuildStructGEP2`, all fed by Phase 1's tracked types.

Both versions remain selectable during transition. That is the rollback.

### Phase 3 — Pass manager

`PassManagerBuilder` is gone; the New Pass Manager entry point is
`LLVMRunPasses(M, "default<O2>", TM, options)` from `Transforms/PassBuilder.h` — **simpler** than
what exists today. Version-gate it: LLVM 9 keeps the legacy path added in #23503, LLVM 22 uses NPM.

### Phase 4 — Regenerate every LLVM-version-specific artifact

Easy to forget and guaranteed to bite:

* `prelinked/helper-runtime/x86_64-linux/**` — 257 object files built by **LLVM 9**;
* `prelinked/bootstrap-gen0/**` and `prelinked/bootstrap-vendor/**`;
* the pinned Docker image (`Docker/dev/ubuntu-22.04/Dockerfile`) and `script/install-llvm9.sh`;
* link flags and sysroot paths in `lib/AOT/Linker.php`.

Objects emitted by LLVM 9 and LLVM 22 cannot be linked into one binary and expected to behave.

### Phase 5 — Validation before cutover

The full gate, because this touches every emitted instruction:

1. `script/differential-sweep.sh` and `--aot` — green;
2. `VMTest` + `JITTest` sharded, compared by failing **case name** against master — no regressions;
3. `benchmarks/README.md` regenerated, all columns output-verified;
4. a self-host build.

## Risks, ranked

1. **No CI on `lib/`** — the sweep and compliance comparison are the entire safety net.
2. **Silent miscompiles.** An unbound cross-module call lowers to `__value__writeNull` with no
   diagnostic (#579). A wrong pointee type could produce working-looking but wrong code — which is
   exactly why Phase 1 is validated on LLVM 9 where a diff means a bug.
3. **IR textual incompatibility.** Already observed: LLVM 9 emits `void*`, which clang 14+ rejects
   (`pointers to void are invalid — use i8*`). Expect more of this class.
4. **MCJIT → ORC.** `createExecutionEngine`/`getTargetMachine` may need reworking; the object-emit
   path depends on it.
5. **Scope creep into performance.** A newer optimiser does not fix interpreter-shaped IR — the O2
   pipeline bought only 16% because boxed values defeat it. Keep this migration separate from the
   speculation experiments (#23483).

## Sequencing note

Phase 1 is the bulk of the work and the only part that is genuinely hard. It is also the part that
can be done and proven **without** LLVM 22 present. Start there once Phase 0's gate answers, and
keep LLVM 9 working the entire time.
