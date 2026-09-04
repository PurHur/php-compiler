# ADR: JIT tier future — AOT + VM Ship; MCJIT serve deferred (#36402)

- Status: Accepted
- Date: 2026-09-04
- Parent: #36402 · Wave 2 #36379

## Decision

**Product Ship tiers are AOT (`phpc build`) and the VM (`phpc run` / `bin/vm.php`).**

1. Keep MCJIT internally where existing gates need it; do **not** market
   `phpc serve --jit` as a supported deployment path for v2.0.
2. After LLVM 22 + ORC land (#36220), revisit a first-class JIT serve tier.
3. Recommendation until then: **retire user-facing JIT serve** in favour of
   AOT binaries + FastCGI/VM workers (#36392).

## Options considered

| Option | Outcome |
|--------|---------|
| Keep MCJIT serve as a peer of AOT | Continues silent-interpret / dual-stack support cost |
| Move to ORC after LLVM 22 | Accepted direction; blocked on #36220 |
| Retire serve-JIT until ORC | **Chosen** for v2.0 messaging |

## Consequences

- Perf roadmap (#36386) optimizes AOT codegen and VM headroom first.
- Docs must not claim “JIT web tier” without an ORC milestone issue.
