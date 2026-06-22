<?php

declare(strict_types=1);

/**
 * Issue #6549 — `new` without `()` in static property initializers must compile-error.
 * Class constants reject all `new` per php-src (#10391); instance property defaults may use bare `new`.
 *
 * Zend reference: Zend/zend_compile.c, Zend/zend_ast.c
 */

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;

function assertCompileFails(string $label, string $code): void
{
    $runtime = new Runtime();
    try {
        $runtime->parseAndCompile($code, 'parity_new_without_parens.php');
        fwrite(STDERR, "FAIL: {$label} — expected compile error, got success\n");
        exit(1);
    } catch (\CompileError $e) {
        if (NewWithoutParensCompileCheck::MESSAGE !== $e->getMessage()) {
            fwrite(STDERR, "FAIL: {$label} — wrong message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "PASS: {$label}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: {$label} — unexpected ".get_class($e).": {$e->getMessage()}\n");
        exit(1);
    }
}

function assertCompileSucceeds(string $label, string $code): void
{
    $runtime = new Runtime();
    try {
        $runtime->parseAndCompile($code, 'parity_new_without_parens.php');
        echo "PASS: {$label}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: {$label} — unexpected ".get_class($e).": {$e->getMessage()}\n");
        exit(1);
    }
}

assertCompileFails('class const', '<?php class C { const X = new stdClass; }');
assertCompileFails('static property', '<?php class C { public static $s = new stdClass; }');
assertCompileSucceeds('instance property', '<?php class C { public $p = new stdClass; }');

echo "ok\n";
