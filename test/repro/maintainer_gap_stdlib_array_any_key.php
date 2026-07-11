<?php

declare(strict_types=1);

if (!function_exists('array_any_key')) {
    echo "fail: array_any_key() not registered\n";
    exit(1);
}

$a = ['x' => 1, 'y' => 2, 'z' => 3];
if (!array_any_key($a, fn ($k, $v) => $k === 'y' && $v === 2)) {
    echo "fail: key y value 2\n";
    exit(1);
}

$b = [10, 20, 30];
if (!array_any_key($b, fn ($k, $v) => $k === 2 && $v === 30)) {
    echo "fail: int key 2\n";
    exit(1);
}

if (array_any_key($a, fn ($k, $v) => $k === 'missing')) {
    echo "fail: no missing key\n";
    exit(1);
}

if (array_any_key([], fn () => true)) {
    echo "fail: empty array is false\n";
    exit(1);
}

echo "ok\n";
