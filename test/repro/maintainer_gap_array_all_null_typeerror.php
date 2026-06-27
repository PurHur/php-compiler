<?php

declare(strict_types=1);

$checks = [
    'array_all' => 'array_all(): Argument #1 ($array) must be of type array, null given',
    'array_any' => 'array_any(): Argument #1 ($array) must be of type array, null given',
    'array_find' => 'array_find(): Argument #1 ($array) must be of type array, null given',
    'array_find_key' => 'array_find_key(): Argument #1 ($array) must be of type array, null given',
];

foreach ($checks as $fn => $expected) {
    try {
        $fn(null, static fn () => true);
        echo "fail: {$fn}(null) expected TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
