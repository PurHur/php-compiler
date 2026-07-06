<?php

declare(strict_types=1);

if (!function_exists('grapheme_str_contains') || !function_exists('grapheme_strimwidth')) {
    echo "fail: function_exists false on forward 8.4 profile\n";
    exit(1);
}
if (!grapheme_str_contains('hello', 'ell') || 'hello' !== grapheme_strimwidth('hello', 0, 10)) {
    echo "fail: callable but wrong result\n";
    exit(1);
}
echo "ok\n";
