<?php

declare(strict_types=1);

/**
 * Maintainer repro: bcround() advertised under PHP_COMPILER_PROFILE=8.4 (#16709).
 *
 * php-src: ext/bcmath/bcmath.c — PHP_FUNCTION(bcround) registered on PHP 8.4+.
 */

if (!function_exists('bcround')) {
    echo "fail: function_exists(bcround) false under PHP_COMPILER_PROFILE=8.4\n";
    exit(1);
}

$result = bcround('1.234', 2);
if ('1.23' !== $result) {
    echo "fail: bcround returned ", var_export($result, true), "\n";
    exit(1);
}

echo "ok: bcround advertised and callable\n";
