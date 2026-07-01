<?php

declare(strict_types=1);

$checks = [
    'array_rand' => static fn () => array_rand(null),
    'array_chunk' => static fn () => array_chunk(null, 1),
    'array_reduce' => static fn () => array_reduce(null, static fn ($c, $i) => $c),
];

$messages = [
    'array_rand' => 'array_rand(): Argument #1 ($array) must be of type array, null given',
    'array_chunk' => 'array_chunk(): Argument #1 ($array) must be of type array, null given',
    'array_reduce' => 'array_reduce(): Argument #1 ($array) must be of type array, null given',
];

foreach ($checks as $fn => $call) {
    try {
        $call();
        echo "fail: {$fn}(null) expected TypeError\n";
        exit(1);
    } catch (TypeError $e) {
        if ($messages[$fn] !== $e->getMessage()) {
            echo 'fail: ', $fn, '(): ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
