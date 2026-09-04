# ADR: Per-unit translation units + helper ABI manifest (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · related #15889 / #24429 / #36147 / #36387

## Decision

**Lowering emits per-unit LLVM objects** with a generated **helper ABI manifest**
(logical helper → symbol + LLVM signature). Callers and definitions both check the
manifest. Init uses composable `llvm.global_ctors` (unique ctor symbols per TU).

The historical “one monolithic module for the whole spine” is a bootstrap tax to
retire, not the target architecture (`docs/architecture-review-2026-07.md` F3).

## Why

- Monolith rebuilds are multi-hour / OOM; php-src compiles comparable C in minutes
  because each `.c` is its own TU.
- Signature drift across helper cache / split emit caused silent wrong binaries
  (#15889).

## Consequences

- Prefer helper-runtime cache + prelinked per-arch objects over whole-tree re-emit.
- Split-TU / Concern extracts (#36387 / #36403) must keep opcode corpus MD5 stable.
- Gen-0 refresh uses Docker memory caps; never restamp fingerprints to fake freshness.
