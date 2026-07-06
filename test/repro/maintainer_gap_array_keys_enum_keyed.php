<?php

declare(strict_types=1);

/**
 * Maintainer repro: enum case array keys must throw at write (re-#9512, #16986).
 *
 * php-src: Zend/zend_hash.c — illegal offset type for enum cases
 */

enum E: int
{
    case A = 1;
    case B = 2;
}

try {
    $a = [];
    $a[E::A] = 'x';
    echo "fail: enum case array key accepted\n";
    exit(1);
} catch (TypeError $e) {
    if ('Illegal offset type' !== $e->getMessage()) {
        echo 'fail: wrong message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
