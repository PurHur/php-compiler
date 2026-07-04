<?php

declare(strict_types=1);

/**
 * Maintainer repro: BcMath\Number OOP surface on PHP 8.4 profile (#15705).
 *
 * php-src: ext/bcmath/bcmath.stub.php — BcMath\Number
 */

use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "fail: BcMath\\Number missing\n";
    exit(1);
}

$a = new Number('1.5');
$b = new Number('2.5');
$result = $a->add($b);
if (!$result instanceof Number) {
    echo "fail: add() did not return Number\n";
    exit(1);
}
if ('4.0' !== $result->value) {
    echo 'fail: value=', var_export($result->value, true), " expected 4.0\n";
    exit(1);
}

echo "ok\n";
