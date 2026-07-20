<?php

declare(strict_types=1);

/**
 * #21493 — class const / property-default `new` must fatal like Zend under PROFILE=8.4.
 * Param defaults and function static `new` remain legal.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_21493_class_const_new_reject.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;

$runtime = new Runtime();

function assertCompileFails(Runtime $runtime, string $label, string $source): void
{
    try {
        $runtime->parseAndCompile($source, $label . '.php');
        fwrite(STDERR, "fail: {$label} compiled (expected reject)\n");
        exit(1);
    } catch (CompileError $e) {
        if (!str_contains($e->getMessage(), NewWithoutParensCompileCheck::MESSAGE)) {
            fwrite(STDERR, "fail: {$label} wrong message: {$e->getMessage()}\n");
            exit(1);
        }
        echo "ok reject: {$label}\n";
    }
}

function assertCompileOk(Runtime $runtime, string $label, string $source): void
{
    $block = $runtime->parseAndCompile($source, $label . '.php');
    if (null === $block) {
        fwrite(STDERR, "fail: {$label} did not compile\n");
        exit(1);
    }
    echo "ok allow: {$label}\n";
}

assertCompileFails(
    $runtime,
    'class_const',
    '<?php class A { public const X = new stdClass; }'
);
assertCompileFails(
    $runtime,
    'class_const_parens',
    '<?php class A { public const X = new stdClass(); }'
);
assertCompileFails(
    $runtime,
    'instance_prop',
    '<?php class A { public $x = new stdClass(); }'
);
assertCompileFails(
    $runtime,
    'static_prop',
    '<?php class A { public static $x = new stdClass(); }'
);
assertCompileOk(
    $runtime,
    'param_default',
    '<?php function f($x = new stdClass) {}'
);
assertCompileOk(
    $runtime,
    'static_var',
    '<?php function g() { static $x = new stdClass; }'
);

echo "issue_21493 ok\n";
