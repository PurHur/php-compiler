<?php

declare(strict_types=1);

if (!function_exists('mb_str_pad')) {
    echo "fail: mb_str_pad not registered\n";
    exit(1);
}

if (!function_exists('mb_str_pad') || !is_callable('mb_str_pad')) {
    echo "fail: introspection false while callable\n";
    exit(1);
}

echo mb_str_pad('hi', 5, '-'), "\n";
