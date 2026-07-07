<?php

declare(strict_types=1);

/**
 * Maintainer repro: crc32c() on forward profile (#17139, #17206, ext/standard/crc32.c).
 *
 * Run: PHP_COMPILER_PROFILE=8.3 php bin/vm.php test/repro/maintainer_gap_crc32c_builtin.php
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
