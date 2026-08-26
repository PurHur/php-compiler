# AOT differential baseline

`script/differential-sweep.sh --aot` is **green on the full corpus** as of `3db6c81f98`. AGENTS.md §2 still
applies when comparing branch vs master: diff failing case **names**, not raw counts.

Captured on **`3db6c81f98`**, `php-compiler:22.04-dev`, PHP 8.2.32, LLVM 9:

```bash
script/differential-sweep.sh --repeat 3              # VM : 125/125 match, exit 0
script/differential-sweep.sh --aot --repeat 3        # AOT: 125/125 match, exit 0
script/aot-smoke.sh                                  # 8/8
```

Run with `--repeat` (#23902). Cases marked `@differential-repeat: N` re-run even in a plain sweep.
Several historical defects failed only *some* runs — a single-run baseline silently bakes in whichever
flaky cases happened to pass that day.

## Corpus note

The corpus grew from targeted bug hunts, so it covers expression shapes densely and everyday code
through batches `i*`, `j*`, `k*`, `m*`, `n*`. Regenerate this file after any batch of lowering work;
a baseline that silently drifts is worse than none.

## Historical failures (all fixed on current master)

Prior captures (`556c97d1d`, `96ddddeb1`, `412a8cf79`) documented mismatches in groups 1–6 of
`docs/roadmap/AOT-CORRECTNESS-PLAN.md` — float→string crashes (#31963), int overflow (#31964),
static property via closure (#31965), invalid IR (#31966), inherited property defaults (#31895),
and related numerics/statics probes. None remain in the failing set at `3db6c81f98`.

Notable fixed rows (non-exhaustive):

| case | fix |
|---|---|
| `i31895_inherited_property_defaults` | #31895 — subclass `new` must copy parent defaults |
| `i31965_static_property_closure_aot` | #31965 — stable static read through closure |
| `i32035_static_property_coalesce_assign` | #32035 — static `??=` store |
| `i33748_instance_property_coalesce_assign` | #33748 — instance `??=` store |
| `i34896_toplevel_closure_static_default` | #34896 — closure reads class static default |
| `g08_inherited_static_share_32301` | #32301 — inherited static storage |
| `j07_array_prop_default` | #24086 — array property literal default |
| `e30_array_lit_dim_assign_shift` | #24055 |
| `g07a_int_string_resource_collision` | #24024 / #24044 — `@differential-repeat: 10` |

## Known limitations (not in failing set — explicit diagnostics or skipped)

**`var_dump()` / `print_r()` of non-scalars** — thin standalone AOT lacks `Runtime->vm` (#23540 / #9190).
Cases that hit this emit an explicit diagnostic and exit; they are not silent wrong output.

**Array-callable comparators** — `e04_usort` documents invokable-comparator deferral.

## Smoke-check the toolchain before believing any sweep

Before attributing a mass failure to anything, compile and run:

```php
<?php echo "hi\n";
```

If that fails, the toolchain is broken and the sweep tells you nothing. Run `script/aot-smoke.sh`
first — every time.

## Do not run two sweep containers against the same bind mount

Concurrent containers sharing the bind-mounted helper cache can produce stable wrong answers that
look like real defects. Re-measure alone if a result is suspicious.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A name-diff only catches *newly
failing* cases — it cannot see a case getting **worse** while remaining in the failing set.
