<?php
/**
 * #24002 — former forward-profile ArrayPadType expecter; now asserts phantoms are gone.
 */
declare(strict_types=1);

if (enum_exists('ArrayPadType', false)) {
    echo "fail: ArrayPadType enum must not be registered\n";
    exit(1);
}
if (defined('ARRAY_PAD_BOTH') || defined('ARRAY_PAD_LEFT') || defined('ARRAY_PAD_RIGHT')) {
    echo "fail: ARRAY_PAD_* must not be defined\n";
    exit(1);
}
if (3 !== (new ReflectionFunction('array_pad'))->getNumberOfParameters()) {
    echo "fail: array_pad Reflection arity must be 3\n";
    exit(1);
}

$positive = array_pad([1], 4, 0);
$expectedPositive = [1, 0, 0, 0];
if ($positive !== $expectedPositive) {
    echo 'fail: sign-of-length pad expected ', var_export($expectedPositive, true),
        ' got ', var_export($positive, true), "\n";
    exit(1);
}

$negative = array_pad([1], -4, 0);
$expectedNegative = [0, 0, 0, 1];
if ($negative !== $expectedNegative) {
    echo 'fail: negative length pad expected ', var_export($expectedNegative, true),
        ' got ', var_export($negative, true), "\n";
    exit(1);
}

echo "ok\n";
