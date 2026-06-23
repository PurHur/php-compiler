<?php

declare(strict_types=1);

/**
 * Issue #10693 — instance typed property `new` default must compile-reject (Zend/zend_compile.c).
 */

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;

$code = <<<'PHP'
<?php
class Logger {}
class C {
    public Logger $l = new Logger();
}
echo "should not reach runtime\n";
PHP;

$runtime = new Runtime();
try {
    $runtime->parseAndCompile($code, 'maintainer_gap_instance_property_new_default.php');
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
