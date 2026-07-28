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
| `i14_nested_dim_assign` | `$g[1][0] = 9` reads back `3`, the original value — #24011 |

**Compile failures — triaged in #23971**

| case | cause |
|---|---|
| `e07_named` | compiler crash: `TypeError` in `BackedEnumFromJit::emitCallSiteStrictCheck()` — #23972 |
| `e16_array_slice` | compiles; runtime still hits `print_r` thin-standalone gap (#23540) — slice itself fixed in #23991 |
| `e30_array_lit_dim_assign_shift` | **regression** — `HashTable::shiftFirst()` exists (`lib/VM/HashTable.php:783`) but is unresolved for AOT; third instance of #23974's pattern — #24025 |
| `e04_usort` | **documented limitation** — array-callable / invokable comparators deferred |
| `e08_spread` | variadic spread not lowered: `Unsupported cast for arg type int64 from __hashtable__*` |
| `c07_method` | `Missing required argument 1` on a two-argument call whose arity is correct |

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

This regeneration caught `e30` and `g07a` as regressions no individual verification would have
surfaced. `g07a` carries `@differential-repeat: 10` so a plain sweep re-runs it.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A baseline that silently drifts is
worse than none: it makes a live regression look like an unchanged failure.

Note also that a name-diff only catches *newly failing* cases — it cannot see a case getting
**worse** while remaining in the failing set, which is how a `sprintf` wrong-output bug became heap
corruption unnoticed (#23871).
