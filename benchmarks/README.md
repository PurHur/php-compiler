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
| typed recursion w/ nested call args | `Ack(3,9)` | ~3.1x slower |

The generated code is not uniformly slow — the fast path is genuinely fast. The gap is
shape-dependent, and the two open shapes are worth stating precisely:

- **`simple` is call overhead, not loop overhead.** It halved (1.0172s -> 0.5087s) when the
  ++/-- resource guard was elided (#23483), which fixed its two 1M-iteration `++` loops. The
  remaining 0.5s is dominated by its *other* three functions, which make 3M function calls
  (`strlen()` and two user functions). Loops are done; calls are not.
- **`Ack` is not an "untyped code" problem.** It is declared `function Ack(int $m, int $n): int`
  and contains no `++`/`--` at all, so #23483's fix does not touch it. Its distinguishing feature
  is `Ack($m - 1, Ack($m, $n - 1))` — a call result feeding another call's argument, the same shape
  as #23472. Cause not yet diagnosed; do not attribute it to missing type information.

Regenerate with `PHP_8_2=$(command -v php) php script/bench.php`. Note that `script/bench.php`
exits **0 while measuring nothing** if no `PHP_X_Y` runtime is exported — check that the table
actually changed before trusting a run.