<?php

declare(strict_types=1);

if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.3') {
    fwrite(STDERR, "skip: requires PHP_COMPILER_PROFILE=8.3 or 8.4\n");
    exit(0);
}

$checks = [
    [1234.5678, -1, '1,230'],
    [12.345, -1, '10'],
    [1.5, -1, '0'],
];

foreach ($checks as [$num, $decimals, $expected]) {
    $got = number_format($num, $decimals);
    if ($got !== $expected) {
        echo "fail: number_format({$num}, {$decimals}) got {$got} expected {$expected}\n";
        exit(1);
    }
}

echo "ok\n";
