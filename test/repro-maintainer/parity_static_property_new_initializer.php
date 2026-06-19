<?php

declare(strict_types=1);

/**
 * Issue #10095 — static typed property `new` default must compile-reject (Zend/zend_compile.c).
 */

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;

$code = <<<'PHP'
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
echo "compiled ok\n";
var_export(C::$d instanceof DateTime);
echo "\n";
PHP;

$runtime = new Runtime();
try {
    $runtime->parseAndCompile($code, 'parity_static_property_new_initializer.php');
    fwrite(STDERR, "FAIL: expected compile error, got success\n");
    exit(1);
} catch (\CompileError $e) {
    if (NewWithoutParensCompileCheck::MESSAGE !== $e->getMessage()) {
        fwrite(STDERR, "FAIL: wrong message: {$e->getMessage()}\n");
        exit(1);
    }
    echo "PASS\n";

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: unexpected '.get_class($e).": {$e->getMessage()}\n");
    exit(1);
}
