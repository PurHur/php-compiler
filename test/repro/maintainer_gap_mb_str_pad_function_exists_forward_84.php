<?php

declare(strict_types=1);

if (!function_exists('mb_str_pad')) {
    echo "fail: function_exists(mb_str_pad) false under PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

if (!is_callable('mb_str_pad')) {
    echo "fail: is_callable(mb_str_pad) false under PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

$result = mb_str_pad('hi', 5, '-');
if ('hi---' !== $result) {
    echo "fail: unexpected pad result: {$result}\n";
    exit(1);
}

echo "ok\n";
