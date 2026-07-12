<?php

declare(strict_types=1);

if (!function_exists('array_all_key')) {
    echo "fail: array_all_key() not registered\n";
    exit(1);
}

$a = ['a' => 1, 'b' => 2, 'c' => 3];
if (!array_all_key($a, fn ($k, $v) => is_string($k) && $v > 0)) {
    echo "fail: string keys all positive\n";
    exit(1);
}

$b = [0 => 10, 1 => 20];
if (!array_all_key($b, fn ($k, $v) => is_int($k) && $v >= 10)) {
    echo "fail: int keys\n";
    exit(1);
}

if (array_all_key($a, fn ($k, $v) => $k === 'c')) {
    echo "fail: not all keys are c\n";
    exit(1);
}

if (!array_all_key([], fn () => false)) {
    echo "fail: empty array is vacuously true\n";
    exit(1);
}

echo "ok\n";
