<?php

declare(strict_types=1);

if (!function_exists('array_all')) {
    echo "fail: array_all() not registered\n";
    exit(1);
}

try {
    if (!array_all([1, 2, 3], 'is_int')) {
        echo "fail: array_all(is_int) expected true\n";
        exit(1);
    }
} catch (\ArgumentCountError $e) {
    echo 'fail: array_all(is_int) threw ', $e->getMessage(), "\n";
    exit(1);
}

if (function_exists('array_all_key')) {
    try {
        if (!array_all_key(['a' => 1], 'is_string')) {
            echo "fail: array_all_key(is_string) expected true\n";
            exit(1);
        }
    } catch (\ArgumentCountError $e) {
        echo 'fail: array_all_key(is_string) threw ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
