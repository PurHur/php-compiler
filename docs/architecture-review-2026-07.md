# Architecture review — July 2026

**Scope:** end-to-end audit of the compile pipeline, gate system, and maintenance
processes, based on two days of instrumented work on master (profiling,
regression forensics for #15632/#15642/#15909/#16010, the #15889 split-compilation
prototype, benchmark refresh #15906/#15907, and the M4 ladder verification).
**Thesis:** the compiler's constraint is no longer feature coverage — it is
**IR correctness discipline, redundant lowering work, and maintenance drag**.
The highest-value rearrangement is architectural, not feature work.

---

## 1. The pipeline as measured

`phpc build -o out script.php` (hello-class, php-compiler:22.04-dev, LLVM 9):

| Phase | Wall | Share | Nature |
|---|---|---|---|
| PHP boot + autoload + JIT context init | 0.66 s | 25% | fixed |
| user-script parse + lower | 0.06 s | 2% | per-script |
| nested php-in-PHP helper lowering | 1.24 s | 47% | **fixed, recomputed every build** |
| LLVM emit object | 0.61 s | 23% | mostly fixed |
| link | 0.14 s | 5% | fixed |

Every build recompiles the identical helper corpus into a fresh monolithic
module. Project builds (006-class) pay ~5.3 s for the same reason.

## 2. Findings

### F1 — The merge gate never exercised LLVM *(fixed: `check-aot-build-smoke.sh`)*
`phpc test --fast` ran zero LLVM lowering. Consequences observed live:
silent-exit binaries shipped (#15632), four core builtins broke user-script AOT
for weeks (#15642), and #15866 broke **every** `phpc build` for ~6 hours while
~40 PRs merged green on top (#16010). The 3 s tier-1 smoke (build + execute +
**VM output differential**) would have blocked all three classes. Differential
output comparison is essential: exit-0-truncation looks like success to a
"did it link" check.

### F2 — Silent-exit failure mode is structural
Two independent bugs produced binaries that exit 0 mid-output (#15632 dangling
blocks; #15889 prototype init-chain loss). LLVM verification runs only inside
`compileCommon`; nothing asserts *behavior*. Every AOT artifact class needs a
VM-differential harness as its acceptance primitive, not link success.
(`AotTest` datasets exist but carry 9 known-failing entries on master, so their
signal is muted — triage them to zero or quarantine known-fails explicitly.)

### F3 — Monolithic module, no ABI contract for helpers
All lowering lands in one module per build. The #15889 prototype proved the
consequences: helpers materialize with whatever signature the first caller
guessed (superset module verification fails with signature drift), and
cross-module reuse dies on the shared `__init__` chain. Required pieces:

1. **Helper ABI registry** — one generated manifest: logical helper →
   symbol + LLVM signature. Callers and definitions both check against it;
   drift becomes a compile-time error instead of a verifier surprise.
2. **Per-TU init via `llvm.global_ctors`** — each translation unit's
   interned-constant setup gets a unique ctor symbol so `ld` keeps them all;
   init becomes composable and TUs become linkable artifacts.
3. Then #15889 completes: cached helper TUs (measured potential: hello
   3.0→1.9 s, 006 project 5.3→2.2 s), parallel emit, and true incremental
   builds fall out of the same boundary.

### F4 — Generated-code quality regressed ~60× vs the project's own history
`benchmarks/README.md` (2020, PHP 7.4 era): native fibo(30) **10× faster** than
Zend. Today: **5.8× slower** (#15907); simple loops 11× slower. The php-in-PHP
migration made every value boxed and every op a call. The fix is the classic
one: type-specialized fast paths — typed int/float locals and params stay
native i64/double in straight-line code, boxing only at escape points. The
CFG/type-inference layer (php-types) already computes what is needed.
Add `script/bench.php` deltas to the gate so codegen regressions surface.

### F5 — The VM cannot survive 1M iterations (#15906)
Silent exit 255 after ~30 s on `benchmarks/simple.php` even at 4 GiB —
per-iteration allocation retention plus a fatal that never reaches stderr.
The VM is the reference executor; its ceiling caps compliance tests, fuzzing,
and differential coverage. Needs allocation reuse in the interpreter loop and
a fatal path that always writes stderr.

### F6 — Maintenance drag dominates fleet throughput
Observed in one day: capability-matrix drift ×2, spine sync ×4 (manual chain:
discover → append → regen → recount → six footnote files + a test assertion →
40-minute sidecar relink), benchmark-row honesty flips ×3, sidecar stamp
races between agents. Mitigations landed: `check-generated-docs.sh` (22 s
pre-merge drift bundle), `spine-sync.sh` (whole chain, one command),
900× faster gap discovery (#15974). Remaining structural fix: footnote counts
should be **one generated include**, not six sed targets; benchmark-row
honesty should regenerate from probe results, not hand-edited cells.

### F7 — Gen-0 blobs are behavioral state the gates cannot see
Committed sidecars embed the compiler *at the commit that built them*. After
the strlen fix, gen binaries kept emitting the broken lowering until refresh —
only `--strict` (~1 h, pre-merge-only) catches it. The sidecar stamp verifies
the *spine list*, not the *compiler behavior*. Cheap improvement: stamp gen-0
with the tree hash of `lib/` + `ext/` and surface "behaviorally stale" as a
distinct, non-blocking warning in the fast gate.

## 3. Target architecture

```
                      ┌───────────────────────────────┐
   script.php ──────▶ │ parse → CFG → types → opcodes │──▶ script TU ──┐
                      └───────────────────────────────┘                │
   helper corpus ───▶ cached helper TUs (ABI manifest, unique ctors) ──┤──▶ ld
   runtime ─────────▶ prelinked runtime/vendor objects (existing)  ────┘
```

- **Everything after the opcode layer is a translation unit** with an ABI
  manifest and its own ctor. TUs cache by fingerprint, emit in parallel, and
  link with plain `ld` semantics — no muldefs, no monolith.
- **Every artifact class has a differential gate**: AOT-vs-VM (landed),
  JIT-vs-VM, gen-N-vs-gen-0 output equivalence (the honest-compile gate the
  Gen-1+ tracker already envisions, #15597/#15603).
- **Docs/counters are generated, never hand-edited.**

## 4. Sequenced plan

| # | Work | Status |
|---|---|---|
| 1 | AOT smoke + VM differential in fast gate | ✅ this PR |
| 2 | `spine-sync.sh` maintenance collapse | ✅ this PR |
| 3 | Fix the four #15642 builtins → flip smoke tier 2 to enforced | open (#15642) |
| 4 | Helper ABI registry + per-TU `llvm.global_ctors` | open (#15889, design in PR #16022) |
| 5 | Complete split compilation: cached helper TUs, parallel emit | open (#15889) |
| 6 | Typed scalar fast paths; bench deltas in gate | open (#15907) |
| 7 | VM iteration ceiling + loud fatals | open (#15906) |
| 8 | Footnotes → generated include; benchmark rows from probe results | open |
| 9 | Gen-0 behavioral staleness warning | open |
| 10 | Honest gen-3 without sidecar recovery | open (#15597/#15603) |

Measured end state this plan targets: **sub-second rebuilds** (cached TUs +
parallel emit), **native ≥ Zend on compute** (typed fast paths; history shows
10× is reachable), and **a gate that structurally cannot ship a broken
compiler** (differential everywhere, generated docs, behavioral stamps).
