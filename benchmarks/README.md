# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

# Benchmark Results

Each example includes a benchmark that compares each mode of operation to just running the file with `php` directly.

<!-- benchmark table start -->

Environment: 8.2.32 · LLVM 9 available · 5 iterations averaged, wall time per run.

| Test Name          | Zend       8.2 (s)| bin/vm.php (s) | bin/jit.php (s) | phpc build (s) | native run (s) |
|--------------------|-------------------|----------------|-----------------|----------------|----------------|
|          Ack(3,10) |            1.5937 |            n/a |            n/a |         8.4420 |         4.1176 |
|           Ack(3,8) |            0.0986 |            n/a |            n/a |         8.3854 |         0.2618 |
|           Ack(3,9) |            0.3258 |            n/a |            n/a |         8.4376 |         1.0177 |
|           fibo(30) |            0.1008 |            n/a |            n/a |         8.4598 |         0.0170 |
|           fibo(32) |            0.2276 |            n/a |            n/a |         8.3694 |         0.0251 |
|         mandelbrot |            0.1518 |            n/a |            n/a |         8.4498 |         0.1699 |
|             simple |            0.0668 |            n/a |            n/a |         8.2235 |         0.5087 |

<!-- benchmark table end -->

## Reading these numbers

`native run` is the column to compare against `Zend 8.2`. `phpc build` is compile time, not run
time, and is roughly flat (~8.4s) regardless of program size because every binary currently links
the full extension set — that is the cost #23480 is about, not a property of the generated code.

Where the compiler stands against Zend on `native run`, as of the table above:

| shape | example | vs Zend |
|---|---|---|
| typed recursion | `fibo_r(int $n): int` — `fibo(32)` | **9.1x faster** |
| tight `++` loop | `build/micro/m_loop.php` | **3.1x faster** |
| float loop | `mandelbrot` | ~1.1x slower |
| call-heavy | `simple` | ~7.6x slower |
| deep recursion (~4000 frames), unoptimised | `Ack(3,9)` | ~3.1x slower (**3.3x faster** at `OPT_LEVEL=2`) |

The generated code is not uniformly slow — the fast path is genuinely fast. The gap is
shape-dependent, and the two open shapes are worth stating precisely:

- **`simple` is call overhead, not loop overhead.** It halved (1.0172s -> 0.5087s) when the
  ++/-- resource guard was elided (#23483), which fixed its two 1M-iteration `++` loops. The
  remaining 0.5s is dominated by its *other* three functions, which make 3M function calls
  (`strlen()` and two user functions). Loops are done; calls are not.
- **`Ack` is not an "untyped code" problem, and not a codegen problem either.** It is declared
  `function Ack(int $m, int $n): int` and contains no `++`/`--`, so #23483's ++/-- fix does not
  touch it. It is slow because **the LLVM optimisation pipeline is off by default** — see below.

An earlier revision of this file blamed `Ack($m - 1, Ack($m, $n - 1))`, i.e. a call result feeding
another call's argument. That was wrong. Matched micro cases in `build/micro/ackloc/` isolate it:

| shape | vs Zend |
|---|---|
| typed recursion, ternary body | 9.9x **faster** |
| identical maths with `if` branches | 9.0x **faster** |
| **nested call as argument** — `idf(idf($i))` | 13.8x **faster** |
| sequential calls, identical call count | 16.2x **faster** |

Neither extra basic blocks nor nested call arguments cost anything. The discriminator is
**recursion depth**: those cases are 1–30 frames deep, `Ack(3,9)` is ~4000. Every temporary lowers
to an `alloca` and stays in memory without the optimiser, so frame size is free when shallow and
dominant when deep.

## Optimisation level

`PHP_COMPILER_OPT_LEVEL` selects the LLVM IR pipeline. It defaults to **0 (off)**. On `Ack(3,9)`,
best of 3, output verified `4093` at every level:

| level | build | run | vs Zend (320ms) | build vs opt0 |
|---|---|---|---|---|
| `0` (default) | 9.2s | 1202ms | 3.8x slower | 1x |
| `1` | 226.7s | 350ms | 1.1x slower | 24.7x |
| **`2`** | 118.2s | **96ms** | **3.3x faster** | 12.9x |
| `3` | 119.0s | 101ms | 3.2x faster | 12.9x |

**Use `PHP_COMPILER_OPT_LEVEL=2` for release builds.** It is the fastest at runtime and strictly
dominates `1`, which is both slower to build and 3.6x slower at runtime.

It is **not** the default because no level is cheap: even `1` costs ~25x the baseline build. That
cost is not spent on your program — the IR module for a *four-line* loop is 111k lines, because
every binary links the full extension set, so LLVM re-optimises the whole stdlib every build. Build
time is therefore a function of the module, not the program (`opt3` on a 4-line loop and on `Ack`
differ by under 1%).

Defaulting this on is blocked on #23480 (side-loaded extensions): shrink the module and the
pipeline becomes cheap. Before changing the default, run the full `--aot` differential sweep under
the optimised level and compare failing case **names** — optimisation can expose latent UB in
generated IR, and that sweep has not been completed at any non-zero level.

Regenerate with `PHP_8_2=$(command -v php) php script/bench.php`. Note that `script/bench.php`
exits **0 while measuring nothing** if no `PHP_X_Y` runtime is exported — check that the table
actually changed before trusting a run.