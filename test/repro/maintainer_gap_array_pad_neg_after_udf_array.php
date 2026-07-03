<?php
declare(strict_types=1);

function hold(array $v): void
{
    json_encode($v);
}

hold([]);
$r1 = array_pad([1, 2], -4, 0);
$expected = [0, 0, 1, 2];
if ($r1 !== $expected) {
    echo 'fail literal: got ', var_export($r1, true), "\n";
    exit(1);
}

$len = -4;
hold([]);
$r2 = array_pad([1, 2], $len, 0);
if ($r2 !== $expected) {
    echo 'fail variable: got ', var_export($r2, true), "\n";
    exit(1);
}

hold([]);
$r3 = array_pad([1, 2], 4, 0);
if ($r3 !== [1, 2, 0, 0]) {
    echo 'fail positive literal: got ', var_export($r3, true), "\n";
    exit(1);
}

$r4 = array_pad([1, 2], -4, 0);
if ($r4 !== $expected) {
    echo 'fail no prior udf: got ', var_export($r4, true), "\n";
    exit(1);
}

echo "ok\n";
