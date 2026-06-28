<?php
declare(strict_types=1);

/**
 * Maintainer repro: ReflectionConstant on 8.4.0-dev forward profile (#12385).
 */

if (!class_exists('ReflectionConstant', false)) {
    echo "fail: ReflectionConstant not registered on 8.4.0-dev\n";
    exit(1);
}

class C12385 {
    public const FOO = 1;
}

$ref = new ReflectionConstant(C12385::class, 'FOO');
if ('FOO' !== $ref->getName() || 1 !== $ref->getValue()) {
    echo "fail: ReflectionConstant introspection mismatch\n";
    exit(1);
}

echo "ok\n";
