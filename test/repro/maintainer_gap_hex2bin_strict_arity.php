<?php

declare(strict_types=1);

/**
 * Maintainer repro: hex2bin() second argument rejected on Zend 8.2 reference profile (#13116).
 *
 * php-src: ext/standard/string.c — PHP 8.3+ optional $strict.
 */

try {
    hex2bin('ab', true);
    echo "fail: hex2bin() accepted second argument\n";
    exit(1);
} catch (ArgumentCountError $e) {
    if ('hex2bin() expects exactly 1 argument, 2 given' !== $e->getMessage()) {
        echo 'fail: unexpected message ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
