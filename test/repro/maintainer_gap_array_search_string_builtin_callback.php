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

if (function_exists('array_find_key')) {
    try {
        if (0 !== array_find_key([1, 2, 3], 'is_int')) {
            echo "fail: array_find_key(is_int) expected 0\n";
            exit(1);
        }
    } catch (\ArgumentCountError $e) {
        echo 'fail: array_find_key(is_int) threw ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
