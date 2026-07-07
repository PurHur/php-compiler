<?php

declare(strict_types=1);

/**
 * Maintainer repro: crc32c() enabled on default dev profile (#17139, ext/standard/crc32.c).
 */

if (!function_exists('crc32c')) {
    echo "fail: crc32c() missing\n";
    exit(1);
}

$vectors = [
    'abc' => 910901175,
    '' => 0,
];

foreach ($vectors as $input => $expected) {
    $actual = crc32c($input);
    if ($actual !== $expected) {
        echo "fail: crc32c(", var_export($input, true), ") expected $expected got $actual\n";
        exit(1);
    }
}

echo "ok\n";
