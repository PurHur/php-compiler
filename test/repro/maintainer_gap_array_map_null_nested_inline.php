<?php

declare(strict_types=1);

$flat = array_map(null, [1, 2, 3]);
if ('1,2,3' !== implode(',', $flat)) {
    echo 'fail: flat ', implode(',', $flat), "\n";
    exit(1);
}

$nested = array_map(null, [[1], [2]]);
$expected = [[1], [2]];
if ($nested !== $expected) {
    echo 'fail: nested ', var_export($nested, true), "\n";
    exit(1);
}

echo "ok\n";
