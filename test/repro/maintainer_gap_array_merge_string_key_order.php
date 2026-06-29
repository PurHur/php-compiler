<?php

declare(strict_types=1);

$merged = array_merge(['a' => 1], array_keys(['b' => 2]));
$expected = ['a' => 1, 0 => 'b'];
if ($merged !== $expected) {
    echo 'fail: got ', var_export($merged, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$o = array_keys(['b' => 2]);
$variable = array_merge(['a' => 1], $o);
if ($variable !== $expected) {
    echo 'fail variable form: got ', var_export($variable, true), "\n";
    exit(1);
}

echo "ok\n";
