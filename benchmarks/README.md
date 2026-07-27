# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

# Benchmark Results

Each example includes a benchmark that compares each mode of operation to just running the file with `php` directly.

<!-- benchmark table start -->

Environment: 8.2.32 · LLVM 9 available · 5 iterations averaged, wall time per run.

| Test Name          | Zend       8.2 (s)| bin/vm.php (s) | bin/jit.php (s) | phpc build (s) | native run (s) |
|--------------------|-------------------|----------------|-----------------|----------------|----------------|
|          Ack(3,10) |            1.8035 |            n/a |            n/a |         8.3113 |         4.0265 |
|           Ack(3,8) |            0.0986 |            n/a |            n/a |         8.3422 |         0.2597 |
|           Ack(3,9) |            0.3186 |            n/a |            n/a |         8.3537 |         1.0024 |
|           fibo(30) |            0.1093 |            n/a |            n/a |         8.3028 |         0.0142 |
|           fibo(32) |            0.2286 |            n/a |            n/a |         8.3128 |         0.0244 |
|         mandelbrot |            0.1532 |            n/a |            n/a |         8.3582 |         0.1676 |
|             simple |            0.0667 |            n/a |            n/a |         8.5173 |         1.0172 |

<!-- benchmark table end -->