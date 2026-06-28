<?php

declare(strict_types=1);

// Repro for #13029 — usort() bool callback return coercion (php-src php_usort_compare).
$a = [3, 1, 2];
usort($a, static fn (int $x, int $y): bool => ($x <=> $y) ? true : false);
$expected = [1, 2, 3];
if ($a !== $expected) {
    echo 'fail: usort bool callback: got ';
    var_export($a);
    echo ' expected ';
    var_export($expected);
    echo "\n";
    exit(1);
}

$b = [3, 1, 2];
usort($b, static fn (int $x, int $y): int => $x <=> $y);
if ($b !== $expected) {
    echo 'fail: usort int callback: got ';
    var_export($b);
    echo ' expected ';
    var_export($expected);
    echo "\n";
    exit(1);
}

echo "ok\n";
