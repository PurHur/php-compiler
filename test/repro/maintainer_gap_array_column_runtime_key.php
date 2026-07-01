<?php

declare(strict_types=1);

$k = 'e';
$rows = [['e' => 1], ['e' => 2]];
$expected = [1, 2];
$actual = array_column($rows, $k);
if ($expected !== $actual) {
    echo 'expected: ', var_export($expected, true), "\n";
    echo 'actual: ', var_export($actual, true), "\n";
    exit(1);
}

echo "OK\n";
