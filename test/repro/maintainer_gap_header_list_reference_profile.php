<?php

declare(strict_types=1);

// Issue #28404 / #12546 — header_list() must never be registered (php-src: headers_list only).
if (function_exists('header_list')) {
    echo "fail: header_list registered\n";
    exit(1);
}

if (!function_exists('headers_list')) {
    echo "fail: headers_list missing\n";
    exit(1);
}

if (!function_exists('header')) {
    echo "header_missing\n";
    exit(1);
}

echo "header_ok\n";
echo "ok\n";
