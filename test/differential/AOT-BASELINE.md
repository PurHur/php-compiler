# AOT differential baseline

`script/differential-sweep.sh --aot` is **not green**, so its exit status alone tells you nothing.
AGENTS.md §2 says to compare failing case **names** against a baseline. This is that baseline, with
a cause for every entry.

Captured on **`9a538bca2`**, `php-compiler:22.04-dev`, PHP 8.2.32, LLVM 9:

```bash
script/differential-sweep.sh --repeat 3              # VM : 75/75 match, exit 0
script/differential-sweep.sh --aot --repeat 5        # AOT: 60/71 match, 4 skipped, exit 11
```

Run with `--repeat` (#23902). Several defects here have failed only *some* runs — measured rates
include **7/20**, 7/10, 6/10 and 3/5 for the same binary on the same input — so a single-run
baseline silently bakes in whichever flaky cases happened to pass that day.

## How to read "the sweep is green"

An earlier revision of this file said *"the silent-wrong-output class is currently empty on this
path"*. That was true of the corpus and misleading about the compiler: the corpus then held 61
cases and **no `foreach`** — no `sort`, no `match`, no promoted constructor. It grew from targeted
bug hunts, so it covered expression shapes densely and everyday code barely.

#24015 added 14 ordinary-PHP cases. Twelve short programs found **six defects in an evening**
(#24008–#24011, #24024, #24025), three of them silent wrong output. All six are now fixed.

The lesson is durable: **these numbers describe this corpus, not PHP.** When the sweep looks clean,
the useful next question is what it does not exercise.

## The 11 failing cases

**One limitation, six cases — `var_dump()` / `print_r()` of non-scalars**

`e01_var_export`, `e02_in_array`, `e03_array_merge`, `e06_byref`, `e13_isset`, `e17_array_pad`

All emit an explicit diagnostic naming #23540 / #9190: the helper needs `Runtime->vm`, which thin
standalone AOT does not have. **Not** silent — they say so and exit.

**Compile / lowering — triaged in #23971**

| case | cause |
|---|---|
| `e04_usort` | **documented limitation** — array-callable / invokable comparators deferred |
| `e08_spread` | variadic spread not lowered: `Unsupported cast for arg type int64 from __hashtable__*` |
| `c07_method` | `Missing required argument 1` on a two-argument call whose arity is correct |
| `e16_array_slice` | compiles; runtime hits the `print_r` thin-standalone gap above — slice itself fixed in #23991 |

**Other**

| case | cause |
|---|---|
| `g07_inc_resource` | `++$resource` TypeError message prints a literal `\n` and omits the stack trace |

**No silent wrong output. No crashes. Nothing unexplained.** Every remaining failure either refuses
to compile or prints why it cannot proceed.

## Fixed on this path over the preceding day

Silent wrong output: `c04_concat`, `c10_builtin`, `c11_strcmp`, `d04_concat_dim`, `e05_sprintf`,
`e09_nested_calls`, `e15_str_fns`, `e24_compare`, `g03_exception_caught`, `g05_float_render`,
`i09_ctor_promotion`, `i10_null_coalesce_assign`, `i14_nested_dim_assign`.

Compile failures and crashes: `e07_named` (#23972), `e20_closure` (#23973), `e23_implode` and
`g04_exception_state` (#23974), `e30_array_lit_dim_assign_shift` (#24025 then #24055),
`i11_foreach_by_ref`, `i12_nested_foreach`, `i13_sort` (#24010),
`g07a_int_string_resource_collision` (#24024 — was 7/20, now 10/10).

Note how often a correct fix **moved** a failure rather than removing it: #23792, #23815, #23911
and #24025 each exposed the next defect behind the one they fixed. "Case still red" has repeatedly
meant progress.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A baseline that silently drifts is
worse than none: it makes a live regression look like an unchanged failure. A regeneration on
`556c97d1d` caught exactly that — `e30` had stopped compiling and `g07a` had gone from 5/5 clean to
7/20 heap-corrupting, neither of which any individual fix verification would have surfaced.

Note also that a name-diff only catches *newly failing* cases — it cannot see a case getting
**worse** while remaining in the failing set, which is how a `sprintf` wrong-output bug became heap
corruption unnoticed (#23871).
