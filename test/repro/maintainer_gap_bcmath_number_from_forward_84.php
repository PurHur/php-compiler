<?php

declare(strict_types=1);

/**
 * Issue #16814 — BcMath\Number::from() on PHP_COMPILER_PROFILE=8.4.
 *
 * php-src: ext/bcmath/bcmath.c — bcmath_number_from (PHP 8.4+ forward profile).
 */
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    fwrite(STDERR, "skip: BcMath\\Number missing\n");
    exit(0);
}

if (!method_exists(Number::class, 'from')) {
    fwrite(STDERR, "fail: BcMath\\Number::from missing\n");
    exit(1);
}

$n = Number::from('1.50');
if (!(string) $n === '1.50') {
    fwrite(STDERR, 'fail: expected 1.50 got '.(string) $n."\n");
    exit(1);
}

$n2 = Number::from(42);
if ((string) $n2 !== '42') {
    fwrite(STDERR, 'fail: int from expected 42 got '.(string) $n2."\n");
    exit(1);
}

echo "ok\n";
