# ADR: Value representation — boxed `__value__` + guarded native paths (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · Wave 2 #36379 · related #23483 / #36386

## Decision

**Every untyped PHP value is a heap-boxed `%__value__` (≈16 B header + payload).**
Typed locals/params/returns may stay native (`i64` / `double` / …) on straight-line
paths **only behind speculation guards**; the generic boxed path remains the fallback
so no feature depends on a successful guess.

## Why

- Offline whole-program type inference for PHP is undecidable (HPHPc lesson).
- Untyped code today is 4–14× slower than Zend because every op dispatches through
  the box; typed recursion already beats Zend (~7×) when values stay native.
- A “box everything” “fix” that removes guards is a regression, not a simplification.

## Consequences

- New lowering must go through `loadValue` / box helpers — never treat a Variable
  slot pointer as a payload.
- Perf work (#36386) is **inline caches, escape analysis, guarded speculation**,
  not a second offline inferencer.
- Benchmarks that print wrong output under AOT are `n/a`, never a speed claim.
