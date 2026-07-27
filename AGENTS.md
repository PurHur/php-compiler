# Working on php-compiler

Entry point for any agent or contributor. Read this before changing anything under `lib/`, `ext/`
or `script/`.

Detailed rules live in `.cursor/rules/*.mdc` (Cursor loads them automatically; read them directly
if your tool does not). Roadmap and release criteria live in `docs/roadmap/`.

---

## The five things that will bite you

### 1. `CLEAN` on a PR means "no checks configured"

There is **no CI on `lib/` or `ext/`**. The single GitHub workflow carries a `paths:` filter that
excludes `lib/JIT/**`, `lib/VM/**` and `ext/**`, and GitHub Actions is off by choice (billing) — see
`.cursor/rules/local-ci-only.mdc`.

So `gh pr view` reporting `MERGEABLE` / `CLEAN` is **not evidence of anything**. Never cite it. Name
the gate you ran locally, and say what you did not cover.

### 2. The compliance suites are not green on master

~407 `VMTest` and ~472 `JITTest` cases fail on an untouched checkout. A failure **count** therefore
proves nothing.

Compare the **set difference of failing case names**, branch vs master, restricted to cases that
completed on both sides. Run both sides — "it fails on my branch" is not a finding until you know
whether it fails on master.

Several cases are order-dependent or flaky (`stdlib/hrtime*`, `proc_get_status_basic`,
`interface_abstract_static_call`, `types/dnf_return_type_error`). **Always re-run an apparent
regression individually, several times, on both sides** before reporting it. Every one investigated
so far turned out to fail on master too.

### 3. Silent wrong output is the characteristic failure mode

The compliance suite asserts against **recorded** expectations, so it only catches what someone
already thought to record. Code that runs to completion and prints the wrong answer sails through.

Run the differential sweep — it compares against **Zend itself**:

```bash
script/differential-sweep.sh          # VM
script/differential-sweep.sh --aot    # AOT — the least-covered path
```

It found 24 such defects that the compliance suite missed (#23354). Mandatory for any change to
argument handling, call lowering, operand/slot resolution or CFG shape.

**VM is 53/53; `--aot` is not green** — 27 of 53 failed on master when it was first run end-to-end
(#23779). So for AOT, compare failing case **names** against master exactly as you would for the
compliance suites; the raw count is not a pass/fail signal.

A case that exercises a feature a backend genuinely does not implement can declare that, with a
reason:

```php
// @differential-skip-aot: print_r() JIT helper requires Runtime->vm from thin standalone init (#9190 / #23540)
```

Without this, such a case fails forever regardless of compiler state and the exit status stops
meaning "regressions". Use it **only** for genuinely unsupported features — never to silence a real
defect. Silencing a failing case is the cheap green of §4 wearing a different hat.

**One run is not a verification.** The program must be deterministic; the *compiler's output need
not be*. Heap corruption in a generated binary is a recurring defect class here, and it does not
fail every run — measured rates for the same binary on the same input have included 7/10, 6/10 and
3/5. A single run therefore passes such a case most of the time, and that has twice caused a live
defect to be closed as fixed (#23842).

```bash
script/differential-sweep.sh --aot --repeat 10   # re-run each built binary 10x
```

`--repeat` multiplies run time only, not compile time, so `--aot --repeat 10` costs barely more
than `--aot`. **Use it before declaring any memory-safety or wrong-output fix good.** A case can
also opt itself in, so it is re-run even in a plain sweep:

```php
// @differential-repeat: 10   heap corruption is intermittent here (#23842)
```

A mismatch on *any* run fails the case, and the report shows the ratio
(`6/10 runs matched — first mismatch on run 3`) so intermittency is visible rather than inferred.

### 4. Never make a gate green without making the artifact work

The recurring failure here is a **cheap green**. Documented instances: the committed gen-0 driver
cannot compile hello-world while every freshness gate passes (#23468, 272 restamps); prelinked blob
copies were reported as native emits via a hardcoded string (#21860); the helper cache sat
fingerprint-stale for 20 days, silently disabling itself (#23457).

Never restamp a fingerprint to clear a gate. A gate must exercise **function, not existence**. And
an empty result set is not a pass — a bad `--filter` prints `No tests executed!` and exits **0**.

See `.cursor/rules/artifact-honesty.mdc`.

### 5. Quote no number you cannot regenerate

Benchmark claims must trace to a committed table from `script/bench.php` or
`script/rebuild-examples.php`, on a quiet machine, in the pinned container — with every runtime's
output verified identical first. A fast wrong binary is not a benchmark: `mandelbrot` currently
renders all `_` under AOT (#23471) and `Ack(3,8)` segfaults (#23472).

---

## Current direction

| workstream | where | status |
|---|---|---|
| Release criteria & phased plan | `docs/roadmap/RELEASE-PLAN.md`, #23474 | active |
| Extensions become side-loaded, php-src shape | `.cursor/rules/extensions-sideloaded.mdc`, #23480 | direction set |
| Generated-code performance | #23483 | measuring |
| LLVM 9 → 22 migration | `docs/roadmap/LLVM22-MIGRATION.md`, `.cursor/rules/llvm-version-migration.mdc` | planned |

Two facts that shape all of it:

* **Extensions are ~72% of the tree** (`ext/` 4,715 files vs `lib/` 1,853) and every binary links all
  75 of them — `echo "hello"` produces a **14.8 MB** binary. Do not add entries to
  `Runtime::loadCoreModules()`.
* **The spine is 6,519 files compiled as one LLVM module**, single-threaded, with no incrementality
  and no checkpointing — which is why a gen-0 rebuild takes ~4.6 h and OOMs. php-src compiles a
  comparable amount of C in minutes because each `.c` is its own translation unit. Granularity, not
  file count, is the fix.

## Performance: what is and is not true

Measured, pinned container, quiet box:

| | |
|---|---|
| typed recursion (`fibo_r(int $n): int`) | **7.7× faster** than Zend |
| untyped loops / calls | **4–14× slower** than Zend |
| native startup (empty script) | **6 ms** vs Zend's 22 ms |

The gap is the **value representation**, not the optimiser: every untyped local is a heap-boxed
`%__value__` reached through a module-level global, with runtime type dispatch per operation.
Enabling the LLVM O2 pipeline (which had **never run** — #23503) bought only 16%, because that IR
shape defeats the optimiser.

Static type inference is a known dead end for PHP — HPHPc tried this exact architecture and Facebook
moved to a JIT because offline type inference for PHP is undecidable. The direction is **speculation
with guards** (the shape YJIT, V8 and Zend's own tracing JIT use), keeping today's generic path as
the fallback so no feature depends on the guess.

## Before you open a PR

1. Run the smallest sufficient gate — `.cursor/rules/phpc-verify` guidance and `local-ci-only.mdc`.
2. Differential sweep for any lowering change.
3. State in the PR **what you ran and what you did not cover**. Partial is fine; overstated is not.
4. Vendor fixes go in `patches/` with an idempotency guard in `script/apply-patches.sh` — never edit
   `vendor/` directly.
