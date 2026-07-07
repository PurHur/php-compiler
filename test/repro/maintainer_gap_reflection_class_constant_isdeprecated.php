<?php

declare(strict_types=1);

/**
 * Maintainer repro: ReflectionClassConstant::isDeprecated() profile gate (#17104).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_class_constant_is_deprecated()
 * Reference profile (unset PHP_COMPILER_PROFILE): method absent (Zend 8.2 parity).
 * Forward profile (PHP_COMPILER_PROFILE=8.4): #[\Deprecated] constant returns true.
 */

class C {
    #[\Deprecated(message: 'Old const', since: '8.4')]
    public const X = 1;
    public const Y = 2;
}

$rc = new ReflectionClassConstant(C::class, 'X');
if (method_exists($rc, 'isDeprecated')) {
    if (getenv('PHP_COMPILER_PROFILE') === '8.4') {
        if (!$rc->isDeprecated()) {
            fwrite(STDERR, "not_deprecated\n");
            exit(1);
        }
        $control = new ReflectionClassConstant(C::class, 'Y');
        if ($control->isDeprecated()) {
            fwrite(STDERR, "FAIL: control constant deprecated\n");
            exit(1);
        }
        echo "deprecated\n";
        exit(0);
    }
    fwrite(STDERR, "FAIL: isDeprecated exposed on reference profile\n");
    exit(1);
}

if (getenv('PHP_COMPILER_PROFILE') === '8.4') {
    fwrite(STDERR, "FAIL: isDeprecated missing on forward profile\n");
    exit(1);
}

echo "ok reference\n";
