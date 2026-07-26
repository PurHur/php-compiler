# Differential corpus

Programs run under Zend and under the compiler, with the two outputs compared directly.

The compliance suite (`test/compliance/cases/**.phpt`) asserts against **recorded** expectations, so
it can only catch what someone already thought to record. This corpus asserts against **Zend
itself**, which is what makes it useful for the failure mode that has no diagnostic: code that runs
to completion and prints the wrong answer.

```
script/differential-sweep.sh              # VM backend
script/differential-sweep.sh --aot        # AOT backend (compiles each program; slow)
script/differential-sweep.sh --dir DIR    # your own programs
```

Exit status is the number of mismatching programs.

## Origin

Written for #23354, where multi-argument calls silently passed the trailing argument's value to
earlier arguments — `f($x + 1, $x + 2)` printed `12 12`, `str_replace($p['from'], $p['to'], 'xy!')`
returned `xy!`. **24 of these 43 programs mismatched Zend on master**, every one of them silent
wrong output, and none of them caught by the compliance suite. They are all fixed (#23356, #23424);
the corpus stays as a regression guard.

## What the cases cover

Deliberately mundane shapes, because that is where silent wrong output hides:

* `c*` — multi-argument calls by producer kind: arithmetic, dim-fetch, property fetch, concat,
  nested calls, method calls, ternary, three-argument calls, builtins.
* `d*` — **mixed** producer kinds in one call, which is where positional heuristics break, plus the
  cases that only fail once a CFG block split strands a temporary.
* `e*` — the shapes the argument-resolution heuristics were individually tuned against:
  `var_export`, `usort`, `in_array`/`array_search`, `array_merge`, `array_slice`, `array_pad`,
  `sprintf`, `str_replace`, `substr`, by-ref parameters, named arguments, spread, closures and
  arrow functions, static calls, constructor promotion, coalesce/isset arguments, property chains.

## Adding cases

Every case must be **deterministic** — no clocks, no randomness, no network, no filesystem writes,
no object ids or hash order in the output. A case that varies between runs makes the sweep useless
as a gate. `hrtime()` is a live example of why (#23443): it is not monotonic here, so any case using
it flips between pass and fail and poisons regression comparisons.

Prefer programs that print something checkable on every path, so a wrong value shows up as a diff
rather than as identical empty output.
