# AOT differential baseline

`script/differential-sweep.sh --aot` is **not green**, so its exit status alone tells you nothing.
AGENTS.md §2 says to compare failing case **names** against a baseline. This is that baseline, with
a cause for every entry.

Captured on **`556c97d1d`**, `php-compiler:22.04-dev`, PHP 8.2.32, LLVM 9:

```bash
script/differential-sweep.sh --repeat 3              # VM : 75/75 match, exit 0
script/differential-sweep.sh --aot --repeat 5        # AOT: 51/71 match, 4 skipped, exit 20
```

Run with `--repeat` (#23902). Several defects here fail only *some* runs — measured rates include
**7/20**, 7/10, 6/10 and 3/5 for the same binary on the same input — so a single-run baseline
silently bakes in whichever flaky cases happened to pass that day.

## Correction to the previous revision

The `96ddddeb1` revision of this file said *"the silent-wrong-output class is currently empty on
this path"*. **That is no longer true, and it was never a claim about PHP.** It described a corpus
that contained 61 cases and **no `foreach`** — no `sort`, no `match`, no promoted constructor. The
corpus grew from targeted bug hunts, so it covered expression shapes densely and everyday code
barely.

#24015 added 14 ordinary-PHP cases. Twelve short programs found **five defects in an evening**,
three of which are silent wrong output. Read any "the sweep is green" claim as being about *this
corpus*, not the language.

## Remaining failing cases (post-`556c97d1d` fixes applied)

**Ordinary PHP — silent wrong output**

| case | cause |
|---|---|
| ~~`i14_nested_dim_assign`~~ | ~~`$g[1][0] = 9` reads back `3`~~ **fixed** — nested FETCH_DIM_W returns live child HT (#24011) |

**Compile failures — triaged in #23971**

| case | cause |
|---|---|
| `e07_named` | ~~compiler crash~~ **fixed** in #23972 — sparse named-arg maps preserve param indices |
| `e16_array_slice` | compiles; runtime still hits `print_r` thin-standalone gap (#23540) — slice itself fixed in #23991 |
| `e30_array_lit_dim_assign_shift` | ~~regression / segfault~~ **fixed** in #24055 — dim-write orphan box sync + nested `[$a]` value-box hashtable dispatch |
| `e04_usort` | **documented limitation** — array-callable / invokable comparators deferred |
| `e08_spread` | ~~variadic cast crash~~ **fixed** (#23971) — NestedJIT `toCall` isolation, call-unpack without list-isList guard, owned HT copy, runtime `nextFreeElement` on spread loops |
| ~~`c07_method`~~ | ~~`Missing required argument 1` on a two-argument call whose arity is correct~~ **fixed** — free function after class no longer inherits leftover `scope->className` (#23971) |

**`var_dump()` / `print_r()` of non-scalars — one limitation, six cases**

`e01_var_export`, `e02_in_array`, `e03_array_merge`, `e06_byref`, `e13_isset`, `e17_array_pad`

All emit an explicit diagnostic naming #23540 / #9190: the helper needs `Runtime->vm`, which thin
standalone AOT does not have. These are **not** silent failures — they say so and exit.

**Other**

| case | cause |
|---|---|
| `g07_inc_resource` | `++$resource` TypeError message prints a literal `\n` and omits the stack trace |

## Fixed since `96ddddeb1`

`e20_closure` (#23973), `e23_implode` and `g04_exception_state` (#23974).

Earlier the same day: `c04_concat`, `c10_builtin`, `c11_strcmp`, `d04_concat_dim`, `e05_sprintf`,
`e09_nested_calls`, `e15_str_fns`, `e24_compare`, `g03_exception_caught`, `g05_float_render`.

## Fixed since this capture (`556c97d1d`)

Verified on current master with `script/differential-sweep.sh --aot --repeat 5` (g07a at `--repeat 10`):

| case | fix |
|---|---|
| `i09_ctor_promotion` | #24008 / #24043 |
| `i10_null_coalesce_assign` | #24009 / #24026 |
| `i11_foreach_by_ref`, `i12_nested_foreach`, `i13_sort` | #24010 / #24022 |
| `g07a_int_string_resource_collision` | #24024 / #24044 — was 7/20; now **10/10** |
| `e30_array_lit_dim_assign_shift` | #24055 — orphan dim-write sync + nested `[$a]` hashtable dispatch; **10/10** |

This regeneration caught `e30` and `g07a` as regressions no individual verification would have
surfaced. `g07a` carries `@differential-repeat: 10` so a plain sweep re-runs it.

## Batch 3 — modern PHP (`k01`–`k09`)

Measured on `412a8cf79`, uncontended: **VM 9/9**, **AOT 3/9** (`--repeat 2` / `--repeat 3`).

Batches 1 and 2 covered everyday PHP. This batch covers constructs that postdate the corpus:
readonly properties, named arguments, enums, first-class callables, argument spread, late static
binding. Six of nine fail.

| case | state | issue |
|---|---|---|
| `k01` readonly promoted properties | ok | — |
| `k02` named arguments, out-of-order + skipped default | ok | — |
| `k03` `static::m()` through an overriding subclass | **`11` vs `21`** | #24169 |
| `k04` by-reference parameter | **no output at all** | #24162 |
| `k05` `str_starts_with()` | **`false` on matching input** | #24161 |
| `k06` backed enum with `match($this)` | compile failure | #24163 |
| `k07` first-class callable `f(...)` | compile failure (builtins core-dump) | #24166 |
| `k08` spread into fixed untyped params | ok — see the warning below | — |
| `k09` variadic pack used as an array | **`Object` vs `6`** | #24167 |

`k03` and `k09` are the ones to note, because both sit next to a case that already passes. `j02`
covers late static binding via `new static()` and `static::class` and is green; method dispatch
through `static::` was never reached. `e08_spread` covers variadic spread and is green; it only ever
feeds the pack to `implode()`, and `array_sum()` on the same pack returns `Object`. **A green case
bounds the shape it actually executes, not the feature it is named after.**

## Do not run two sweep containers against the same bind mount

I reported `k08` as silent wrong output (`0` instead of `6`) and had to retract it. The probe that
produced that number was running **concurrently with a second sweep container over the same
bind-mounted repo**, so both built through the same helper-runtime cache in `/app`. Re-measured
alone, the identical program passes **10/10**.

This failure mode is dangerous precisely because it does not look flaky: it produced a stable,
plausible wrong answer across all three runs of a `--repeat 3` sweep. `--repeat` does not defend
against it, because every repeat shares the contaminated artifact. Only re-running alone does.

Some existing "flaky case" attributions in this file may deserve re-measurement under that lens.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A baseline that silently drifts is
worse than none: it makes a live regression look like an unchanged failure.

Note also that a name-diff only catches *newly failing* cases — it cannot see a case getting
**worse** while remaining in the failing set, which is how a `sprintf` wrong-output bug became heap
corruption unnoticed (#23871).
