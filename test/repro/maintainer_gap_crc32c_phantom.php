<?php

declare(strict_types=1);

/**
 * Maintainer repro: crc32c() must not exist on Zend 8.2 reference profile (issue #11920).
 *
 * php-src: Castagnoli CRC via ext/hash hash('crc32c', $data); no standalone crc32c().
 */

if (function_exists('crc32c')) {
    echo "fail: crc32c phantom registered\n";
    exit(1);
}

if (!function_exists('crc32')) {
    echo "fail: crc32 missing\n";
    exit(1);
}

echo "ok: crc32c not registered\n";
