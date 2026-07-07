<?php

declare(strict_types=1);

$checks = [
    'array_find' => 'array_find(): Argument #2 ($callback) must be a valid callback, no array or string given',
    'array_find_key' => 'array_find_key(): Argument #2 ($callback) must be a valid callback, no array or string given',
    'array_all' => 'array_all(): Argument #2 ($callback) must be a valid callback, no array or string given',
    'array_any' => 'array_any(): Argument #2 ($callback) must be a valid callback, no array or string given',
    'array_all_key' => 'array_all_key(): Argument #2 ($callback) must be a valid callback, no array or string given',
    'array_any_key' => 'array_any_key(): Argument #2 ($callback) must be a valid callback, no array or string given',
];

foreach ($checks as $fn => $expected) {
    try {
        if ('array_find_key' === $fn) {
            $fn(['a' => 1], null);
        } else {
            $fn([1], null);
        }
        echo "fail: {$fn}([..], null) expected TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
