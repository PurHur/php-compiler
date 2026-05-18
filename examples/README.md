# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

# Benchmark Results

Each example includes a benchmark that compares each mode of operation to just running the file with `php` directly.

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.00695 |         0.03487 |         0.05455 |             n/a |             n/a |
|        001-SimpleWeb |         0.00714 |         0.03649 |         0.05764 |             n/a |             n/a |
|        002-StaticWeb |         0.00713 |         0.03538 |         0.05463 |             n/a |             n/a |
<!-- benchmark table end -->
