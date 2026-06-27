<?php

declare(strict_types=1);

$checks = [
    ['filter_var', ['x', null], 'filter_var(): Argument #2 ($filter) must be of type int, null given'],
    ['filter_input', [INPUT_GET, 'q', null], 'filter_input(): Argument #3 ($filter) must be of type int, null given'],
];

foreach ($checks as [$fn, $args, $expected]) {
    try {
        $fn(...$args);
        echo "fail: {$fn}() expected TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
