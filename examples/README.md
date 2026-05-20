# Examples

Each folder here contains a working PHP example `example.php`, and associated generated files (including LLVM IR generated from said file).

Web examples **001-SimpleWeb** and **002-StaticWeb** ship a minimal `phpc.json` beside `example.php` (issue [#274](https://github.com/PurHur/php-compiler/issues/274)):

```json
{
    "entry": "example.php",
    "binary": ".phpc/bin/app"
}
```

`entry` is the script to compile; `binary` is the default AOT output path used by `phpc serve --aot` (see `lib/Web/ProjectManifest.php`). Build from the example directory:

```console
cd examples/001-SimpleWeb
../../phpc build -o .phpc/bin/app example.php
../../phpc serve --aot 127.0.0.1:8080 .          # docroot = cwd; binary from phpc.json
```

Same layout for `examples/002-StaticWeb`. Project-mode `phpc build` with no extra flags (issue [#106](https://github.com/PurHur/php-compiler/issues/106)) will read `entry` from the manifest later.

CI runs `test/unit/ExamplesCompileTest.php`: every `examples/*/example.php` is checked with `bin/lint.php` / `phpc lint` (structured unsupported-syntax output with GitHub issue links), linted and smoke-run under `bin/vm.php`; when LLVM 9 is available, `bin/compile.php -l` is exercised as well. **001-SimpleWeb** is built with `compile.php` and run twice with different `QUERY_STRING` values to guard runtime superglobal refresh; **002-StaticWeb** is built and executed once (stdout must contain `Hello World`) via `compile.php` and via `phpc build` (issues [#247](https://github.com/PurHur/php-compiler/issues/247), [#282](https://github.com/PurHur/php-compiler/issues/282)).

Before opening a PR that touches web examples or `bin/serve.php`, run:

```console
make web-smoke              # lint + VM ?name= smoke
make examples-web-smoke     # phpc serve + curl (001-SimpleWeb, 002-StaticWeb)
cd examples/001-SimpleWeb && ../../phpc build -o .phpc/bin/app example.php
./script/examples-web-smoke.sh --aot   # uses .phpc/bin/app when present per example
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
