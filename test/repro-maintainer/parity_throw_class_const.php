<?php

declare(strict_types=1);

/**
 * Issue #6580 — throw in class constant expressions must compile-error.
 *
 * Zend reference: Zend/zend_ast.c, Zend/zend_compile.c
 */

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Runtime;

function assertCompileFails(string $label, string $code): void
{
    $runtime = new Runtime();
    try {
        $runtime->parseAndCompile($code, 'parity_throw_class_const.php');
        fwrite(STDERR, "FAIL: {$label} — expected compile error, got success\n");
        exit(1);
    } catch (\CompileError $e) {
        if (ThrowInClassConstCompileCheck::MESSAGE !== $e->getMessage()) {
            fwrite(STDERR, "FAIL: {$label} — wrong message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "PASS: {$label}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: {$label} — unexpected ".get_class($e).": {$e->getMessage()}\n");
        exit(1);
    }
}

assertCompileFails('class const throw', '<?php class C { const X = throw new Exception("x"); }');

echo "ok\n";
