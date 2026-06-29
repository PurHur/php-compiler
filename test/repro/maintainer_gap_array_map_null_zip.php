<?php

declare(strict_types=1);

$zipped = array_map(null, [1, 2], [3, 4]);
$expected = [[1, 3], [2, 4]];
if ($zipped !== $expected) {
    echo 'fail: got ', var_export($zipped, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

echo "ok\n";
