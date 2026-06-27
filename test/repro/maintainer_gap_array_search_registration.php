<?php

declare(strict_types=1);

/**
 * Maintainer repro: PHP 8.4 array search builtins withheld on Zend 8.2 reference profile (#12796).
 *
 * php-src: ext/standard/array.c — array_find, array_find_key, array_all, array_any, array_first, array_last.
 */

$phantoms = [];
foreach ([
    'array_find',
    'array_find_key',
    'array_any',
    'array_all',
    'array_first',
    'array_last',
] as $fn) {
    if (\function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' registered on Zend 8.2 reference profile';
    exit(1);
}

echo "ok\n";
