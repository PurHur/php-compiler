# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

# Benchmark Results

Each example includes a benchmark that compares each mode of operation to just running the file with `php` directly.

<!-- benchmark table start -->

Environment: 8.2.32 · LLVM 9 available · 5 iterations averaged, wall time per run.

| Test Name          | Zend       8.2 (s)| bin/vm.php (s) | bin/jit.php (s) | phpc build (s) | native run (s) |
|--------------------|-------------------|----------------|-----------------|----------------|----------------|
|          Ack(3,10) |            1.6108 |            n/a |            n/a |            n/a |            n/a |
|           Ack(3,8) |            0.0983 |            n/a |            n/a |            n/a |            n/a |
|           Ack(3,9) |            0.3286 |            n/a |            n/a |            n/a |            n/a |
|           fibo(30) |            0.1019 |            n/a |            n/a |         5.5706 |         0.0133 |
|           fibo(32) |            0.2278 |            n/a |            n/a |         5.3847 |         0.0298 |
|         mandelbrot |            0.1545 |            n/a |            n/a |            n/a |            n/a |
|             simple |            0.0658 |            n/a |            n/a |         5.5292 |         1.0131 |

<!-- benchmark table end -->