# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

CI runs `test/unit/ExamplesCompileTest.php`: every `examples/*/example.php` is checked with `bin/lint.php` / `phpc lint` (structured unsupported-syntax output with GitHub issue links), linted and smoke-run under `bin/vm.php`; when LLVM 9 is available, `bin/compile.php -l` is exercised as well, and `001-SimpleWeb` is built to a temp AOT binary and run twice with different `QUERY_STRING` values to guard runtime superglobal refresh.

Before opening a PR that touches web examples or `bin/serve.php`, run:

```console
make web-smoke              # lint + VM ?name= smoke
make examples-web-smoke     # phpc serve + curl (001-SimpleWeb, 002-StaticWeb)
./script/examples-web-smoke.sh --aot   # after phpc build -o .phpc/bin/app in an example dir
```

See issue [#262](https://github.com/PurHur/php-compiler/issues/262) for a full run matrix per folder.

# Benchmark Results

Each example includes a benchmark that compares each mode of operation to just running the file with `php` directly.

<!-- benchmark table start -->

|         Example Name |      Native PHP |      bin/vm.php |     bin/jit.php | bin/compile.php |      ./compiled |
|----------------------|-----------------|-----------------|-----------------|-----------------|-----------------|
|       000-HelloWorld |         0.00695 |         0.03487 |         0.05455 |             n/a |             n/a |
|        001-SimpleWeb |         0.00714 |         0.03649 |         0.05764 |             n/a |             n/a |
|        002-StaticWeb |         0.00713 |         0.03538 |         0.05463 |             n/a |             n/a |
<!-- benchmark table end -->
