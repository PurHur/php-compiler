<?php
// #30480 — array_diff() variadic TypeError cites bool given (php-src ext/standard/array.c)
declare(strict_types=1);

$checks = [
    'array_diff' => static fn () => array_diff([1, 2, 3], [2, 4], true),
    'array_intersect' => static fn () => array_intersect([1, 2], [1], true),
    'array_merge' => static fn () => array_merge([1], true),
];

foreach ($checks as $label => $call) {
    try {
        $call();
        echo "$label: no throw\n";
    } catch (Throwable $e) {
        echo "$label:", $e->getMessage(), "\n";
    }
}
