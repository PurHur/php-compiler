<?php

declare(strict_types=1);

$parts = preg_split('//u', 'abc');
$expected = ['', 'a', 'b', 'c', ''];
if ($parts !== $expected) {
    echo 'basic_fail:', var_export($parts, true), "\n";
    exit(1);
}

$uber = preg_split('//u', 'übc');
$uberExpected = ['', 'ü', 'b', 'c', ''];
if ($uber !== $uberExpected) {
    echo 'utf8_fail:', var_export($uber, true), "\n";
    exit(1);
}

$limited = preg_split('//u', 'abc', 2);
if ($limited !== ['', 'abc']) {
    echo 'limit_fail:', var_export($limited, true), "\n";
    exit(1);
}

$trimmed = preg_split('//u', 'abc', -1, PREG_SPLIT_NO_EMPTY);
if ($trimmed !== ['a', 'b', 'c']) {
    echo 'noempty_fail:', var_export($trimmed, true), "\n";
    exit(1);
}

echo "ok\n";
