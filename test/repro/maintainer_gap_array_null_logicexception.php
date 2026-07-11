<?php

declare(strict_types=1);

$checks = [
    'array_unique' => 'array_unique(): Argument #1 ($array) must be of type array, null given',
    'array_reverse' => 'array_reverse(): Argument #1 ($array) must be of type array, null given',
    'array_change_key_case' => 'array_change_key_case(): Argument #1 ($array) must be of type array, null given',
    'array_filter' => 'array_filter(): Argument #1 ($array) must be of type array, null given',
];

foreach ($checks as $fn => $expected) {
    try {
        $fn(null);
        echo "fail: {$fn}() no TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
