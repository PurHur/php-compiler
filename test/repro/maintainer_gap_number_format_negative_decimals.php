<?php

declare(strict_types=1);

// Issue #16356 — php-src ext/standard/number_format.c negative $decimals (8.3+).
$ok = true;
$checks = [
    [12.345, -1, '12'],
    [12.345, -2, '12'],
    [1.5, -1, '2'],
    [1234.5678, -1, '1,235'],
];
foreach ($checks as [$num, $decimals, $expected]) {
    $got = number_format($num, $decimals);
    if ($got !== $expected) {
        echo "fail: number_format($num, $decimals) got $got expected $expected\n";
        $ok = false;
    }
}
echo $ok ? "ok\n" : "fail\n";
