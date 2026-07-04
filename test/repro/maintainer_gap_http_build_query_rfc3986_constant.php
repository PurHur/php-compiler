<?php

declare(strict_types=1);

$expected = 'a%5Bb%5D=1&a%5Bc%5D=2';
$actual = http_build_query(['a' => ['b' => 1, 'c' => 2]], '', '&', PHP_QUERY_RFC3986);
if ($actual !== $expected) {
    echo "fail: got {$actual} expected {$expected}\n";
    exit(1);
}

echo "ok\n";
