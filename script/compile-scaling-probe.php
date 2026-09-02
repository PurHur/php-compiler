#!/usr/bin/env php
<?php
/**
 * Measure Compiler::compile scaling on repeated call statements (#36224).
 *
 * Usage: php script/compile-scaling-probe.php [50] [100] [200] [400]
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PHPCompiler\Compiler;
use PHPCompiler\Runtime;

$counts = array_slice($argv, 1);
if ([] === $counts) {
    $counts = ['50', '100', '200'];
}

$runtime = new Runtime();

foreach ($counts as $raw) {
    $n = (int) $raw;
    if ($n <= 0) {
        fwrite(STDERR, "Invalid count: {$raw}\n");
        exit(1);
    }
    $src = "<?php function f() {\n";
    for ($i = 0; $i < $n; ++$i) {
        $src .= '    str_pad(implode(",", array_map("strval", [1,2,3])), 5);' . "\n";
    }
    $src .= "}\n";

    $filename = 'build/scale-probe-' . $n . '.php';
    $script = $runtime->parse($src, $filename);

    $compiler = new Compiler();
    $t0 = hrtime(true);
    $compiler->compile($script);
    $ms = (hrtime(true) - $t0) / 1_000_000;
    $perStmt = $ms / $n;
    printf("%d stmts: %.2fs (%.0f ms/statement)\n", $n, $ms / 1000, $perStmt);
}
