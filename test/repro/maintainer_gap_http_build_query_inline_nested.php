<?php

declare(strict_types=1);

$expected = 'a%5Bb%5D=1&a%5Bc%5D=2';
$actual = http_build_query(['a' => ['b' => 1, 'c' => 2]], '', '&', PHP_QUERY_RFC3986);
if ($actual !== $expected) {
    echo "fail: inline nested got {$actual} expected {$expected}\n";
    exit(1);
}

$d = ['a' => ['b' => 1, 'c' => 2]];
$varActual = http_build_query($d, '', '&', PHP_QUERY_RFC3986);
if ($varActual !== $expected) {
    echo "fail: variable nested got {$varActual}\n";
    exit(1);
}

$flat = http_build_query(['x' => 1, 'y' => 2]);
if ('x=1&y=2' !== $flat) {
    echo "fail: flat inline got {$flat}\n";
    exit(1);
}

echo "ok\n";
