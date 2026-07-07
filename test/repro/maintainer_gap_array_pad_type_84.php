<?php
declare(strict_types=1);

if (!enum_exists('ArrayPadType', false)) {
    echo "fail: ArrayPadType enum not registered\n";
    exit(1);
}

$positive = array_pad([1], 4, 0, ArrayPadType::Positive);
$expectedPositive = [1, 0, 0, 0];
if ($positive !== $expectedPositive) {
    echo 'fail: ArrayPadType::Positive expected ', var_export($expectedPositive, true),
        ' got ', var_export($positive, true), "\n";
    exit(1);
}

$negative = array_pad([1], 4, 0, ArrayPadType::Negative);
$expectedNegative = [0, 0, 0, 1];
if ($negative !== $expectedNegative) {
    echo 'fail: ArrayPadType::Negative expected ', var_export($expectedNegative, true),
        ' got ', var_export($negative, true), "\n";
    exit(1);
}

if (!defined('ARRAY_PAD_BOTH')) {
    echo "fail: ARRAY_PAD_BOTH not defined\n";
    exit(1);
}

$both = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);
$expectedBoth = [0, 0, 1, 2, 0];
if ($both !== $expectedBoth) {
    echo 'fail: ARRAY_PAD_BOTH expected ', var_export($expectedBoth, true),
        ' got ', var_export($both, true), "\n";
    exit(1);
}

echo "ok\n";
