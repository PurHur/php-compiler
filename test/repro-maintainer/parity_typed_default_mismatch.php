<?php

declare(strict_types=1);

/**
 * Issue #6558 — typed property/parameter defaults must compile-error on type mismatch.
 *
 * Zend reference: Zend/zend_compile.c — zend_verify_const_expr_type()
 */

require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;

function assertCompileFails(string $label, string $code, string $expectedFragment): void
{
    $runtime = new Runtime();
    try {
        $runtime->parseAndCompile($code, 'parity_typed_default_mismatch.php');
        fwrite(STDERR, "FAIL: {$label} — expected compile error, got success\n");
        exit(1);
    } catch (\CompileError $e) {
        if (!str_contains($e->getMessage(), $expectedFragment)) {
            fwrite(STDERR, "FAIL: {$label} — wrong message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "PASS: {$label}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL: {$label} — unexpected ".get_class($e).": {$e->getMessage()}\n");
        exit(1);
    }
}

assertCompileFails(
    'static property int for string',
    '<?php class C { public static string $s = 123; }',
    'Cannot use int as default value for property C::$s of type string'
);
assertCompileFails(
    'instance property string for int',
    '<?php class C { public int $x = "abc"; }',
    'Cannot use string as default value for property $x of type int'
);
assertCompileFails(
    'parameter string for int',
    '<?php function f(int $x = "abc") {}',
    'Cannot use string as default value for parameter $x of type int'
);

echo "ok\n";
