<?php

declare(strict_types=1);

if (!function_exists('mb_trim')) {
    echo "fail: mb_trim not registered\n";
    exit(1);
}

if (!function_exists('mb_ltrim') || !function_exists('mb_rtrim')) {
    echo "fail: mb_ltrim/mb_rtrim not registered\n";
    exit(1);
}

$s = "\u{3000}hi\u{3000}";
if ('hi' !== mb_trim($s)) {
    echo "fail: mb_trim result\n";
    exit(1);
}

echo "ok\n";
