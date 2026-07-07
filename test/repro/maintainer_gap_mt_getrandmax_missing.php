<?php

declare(strict_types=1);

if (!function_exists('mt_rand')) {
    echo "skip: mt_rand missing\n";
    exit(0);
}

if (!function_exists('mt_getrandmax')) {
    echo "fail: mt_getrandmax() undefined while mt_rand() exists\n";
    exit(1);
}

$max = mt_getrandmax();
if (2147483647 !== $max) {
    echo 'fail: mt_getrandmax()=', var_export($max, true), "\n";
    exit(1);
}

echo "ok max=$max\n";
