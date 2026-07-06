<?php

declare(strict_types=1);

// Maintainer repro: array_udiff_uassoc() bool-returning closure comparators (#11219, ext/standard/array.c).
$a = ['x' => 1, 'y' => 2];
$b = ['x' => 1, 'z' => 3];
$result = array_udiff_uassoc($a, $b, fn ($x, $y) => $x < $y, fn ($k1, $k2) => $k1 < $k2);
$expected = ['y' => 2];
if ($result !== $expected) {
    echo 'fail: got ', var_export($result, true), ' expected ', var_export($expected, true), "\n";
    exit(1);
}

$closureKey = array_udiff_uassoc(['a' => 1], ['A' => 1], 'strcasecmp', fn ($k1, $k2) => strcasecmp($k1, $k2));
if ($closureKey !== []) {
    echo 'fail: strcasecmp+closure key expected [], got ', var_export($closureKey, true), "\n";
    exit(1);
}

echo "ok\n";
