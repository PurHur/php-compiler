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

## The 20 failing cases

**Ordinary PHP — silent wrong output (#24008–#24011)**

| case | cause |
|---|---|
| `i09_ctor_promotion` | promoted property reads `1025` not `4`; `(new Sq(4))->area()` = `1050625`. A classic constructor works — #24008 |
| `i10_null_coalesce_assign` | `??=` leaves the variable empty, for null **and** non-null — #24009 |
| `i14_nested_dim_assign` | `$g[1][0] = 9` reads back `3`, the original value — #24011 |

**Ordinary PHP — compile failures (#24010)**

| case | cause |
|---|---|
| `i11_foreach_by_ref` | `Current basic block has no parent function` (internal invariant) |
| `i12_nested_foreach` | `Unknown array write op: PHPCfg\Op\Iterator\Value`; a *single* foreach is fine |
| `i13_sort` | `sort`/`ksort` fail to compile; `sort()` alone segfaults at runtime |

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
| `g07a_int_string_resource_collision` | **regression** — heap corruption, **7/20 runs correct**; was 5/5 clean earlier. Carries `@differential-repeat: 10` — #24024 |
| `g07_inc_resource` | `++$resource` TypeError message prints a literal `\n` and omits the stack trace |

## Fixed since `96ddddeb1`

`e20_closure` (#23973), `e23_implode` and `g04_exception_state` (#23974).

Earlier the same day: `c04_concat`, `c10_builtin`, `c11_strcmp`, `d04_concat_dim`, `e05_sprintf`,
`e09_nested_calls`, `e15_str_fns`, `e24_compare`, `g03_exception_caught`, `g05_float_render`.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A baseline that silently drifts is
worse than none: it makes a live regression look like an unchanged failure.

**This regeneration earned that.** It caught two regressions no individual verification would have
surfaced — `e30` (stopped compiling) and `g07a` (heap corruption at 7/20, which a single-run check
passes 35% of the time).

Note also that a name-diff only catches *newly failing* cases — it cannot see a case getting
**worse** while remaining in the failing set, which is how a `sprintf` wrong-output bug became heap
corruption unnoticed (#23871).
