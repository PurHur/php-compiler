<?php

declare(strict_types=1);

/**
 * Maintainer repro: str_increment/str_decrement withheld on Zend 8.2 reference profile (#12378).
 *
 * php-src: ext/standard/string.c — PHP 8.3+.
 */

$phantoms = [];
foreach (['str_increment', 'str_decrement'] as $fn) {
    if (\function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' registered on Zend 8.2 reference profile';
    exit(1);
}

echo "ok\n";
