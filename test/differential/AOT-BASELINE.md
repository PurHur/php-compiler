# AOT differential baseline

`script/differential-sweep.sh --aot` is **not green**, so its exit status alone tells you nothing.
AGENTS.md §2 says to compare failing case **names** against a baseline. This is that baseline, with
a cause for every entry.

Captured on **`96ddddeb1`**, `php-compiler:22.04-dev`, PHP 8.2.32, LLVM 9:

```bash
script/differential-sweep.sh --repeat 3              # VM : 60/60 match, exit 0
script/differential-sweep.sh --aot --repeat 5        # AOT: 41/56 match, 4 skipped, exit 15
```

Run with `--repeat` (#23902). Several defects here fail only *some* runs — measured rates have
included 7/10, 6/10 and 3/5 for the same binary on the same input — so a single-run baseline
silently bakes in whichever flaky cases happened to pass that day.

## Remaining failing cases

**Compile failures — triaged in #23971**

| case | cause |
|---|---|
| `e07_named` | ~~compiler crash~~ **fixed** in #23972 — sparse named-arg maps preserve param indices |
| `e16_array_slice` | compiles; runtime still hits `print_r` thin-standalone gap (#23540) — slice itself fixed in #23991 |
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

## Fixed on this path today, for reference

`c04_concat`, `c10_builtin`, `c11_strcmp`, `d04_concat_dim`, `e05_sprintf`, `e09_nested_calls`,
`e15_str_fns`, `e20_closure`, `e23_implode`, `e24_compare`, `g03_exception_caught`, `g04_exception_state`,
`g05_float_render`.

The silent-wrong-output class — code that runs to completion and prints the wrong answer, which
AGENTS.md §3 calls the characteristic failure mode — is currently **empty** on this path. Every
remaining failure either refuses to compile or says why it cannot proceed.

## Keeping this honest

Regenerate after any batch of lowering work and update the SHA. A baseline that silently drifts is
worse than none: it makes a live regression look like an unchanged failure. Note also that a
name-diff only catches *newly failing* cases — it cannot see a case getting **worse** while
remaining in the failing set, which is how a `sprintf` wrong-output bug became heap corruption
unnoticed (#23871).
