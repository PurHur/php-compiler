<?php

declare(strict_types=1);

if (!\defined('ARRAY_PAD_RIGHT') || !\defined('ARRAY_PAD_LEFT') || !\defined('ARRAY_PAD_BOTH')) {
    // Host Zend PHP may not define these yet (newer PHP versions); treat as "skip" there.
    echo "skip: ARRAY_PAD_* constants missing\n";
    exit(0);
}

$rf = new ReflectionFunction('array_pad');
if (4 !== $rf->getNumberOfParameters()) {
    echo "fail: array_pad parameter count is {$rf->getNumberOfParameters()}\n";
    exit(1);
}

$r = array_pad([1, 2], 5, 0, ARRAY_PAD_RIGHT);
$l = array_pad([1, 2], 5, 0, ARRAY_PAD_LEFT);
$b = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);

if ([1, 2, 0, 0, 0] !== $r) {
    echo "fail: right\n";
    var_export($r);
    echo "\n";
    exit(1);
}
if ([0, 0, 0, 1, 2] !== $l) {
    echo "fail: left\n";
    var_export($l);
    echo "\n";
    exit(1);
}
// Both: extra pad goes on the right.
if ([0, 0, 1, 2, 0] !== $b) {
    echo "fail: both\n";
    var_export($b);
    echo "\n";
    exit(1);
}

echo "ok\n";

