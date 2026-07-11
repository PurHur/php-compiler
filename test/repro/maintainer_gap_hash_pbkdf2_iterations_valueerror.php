<?php

declare(strict_types=1);

$fail = 0;

foreach ([0, -1] as $iterations) {
    try {
        hash_pbkdf2('sha256', 'password', 'salt', $iterations, 32);
        echo "iterations={$iterations}: no exception\n";
        $fail = 1;
    } catch (ValueError $e) {
        if ('hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0' !== $e->getMessage()) {
            echo "iterations={$iterations}: bad message: {$e->getMessage()}\n";
            $fail = 1;
        }
    }
}

try {
    hash_pbkdf2('sha256', 'password', 'salt', 1, -1);
    echo "length=-1: no exception\n";
    $fail = 1;
} catch (ValueError $e) {
    if ('hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0' !== $e->getMessage()) {
        echo "length=-1: bad message: {$e->getMessage()}\n";
        $fail = 1;
    }
}

echo $fail === 0 ? "ok\n" : "fail\n";
exit($fail);
