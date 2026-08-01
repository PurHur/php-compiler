<?php
// Maintainer repro for #26517 — void parameter / void-in-union must compile-fatal.
error_reporting(E_ALL);

$cases = [
    'void_param' => '<?php function f(void $x) {} echo "ok\n";',
    'void_union_return' => '<?php function f(): int|void {} echo "ok\n";',
    'void_ok' => '<?php function f(): void {} echo "ok\n";',
];

foreach ($cases as $name => $code) {
    $file = sys_get_temp_dir() . '/issue_26517_' . $name . '.php';
    file_put_contents($file, $code);
    echo "== $name ==\n";
    try {
        $runtime = new PHPCompiler\Runtime();
        $runtime->parseAndCompile($code, $file);
        echo "compiled\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
