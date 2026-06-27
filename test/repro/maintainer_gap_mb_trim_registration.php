<?php

declare(strict_types=1);

/**
 * Maintainer repro: mb_trim family withheld on Zend 8.2 reference profile (#12797).
 *
 * php-src: ext/mbstring/mbstring.c — mb_trim, mb_ltrim, mb_rtrim (PHP 8.4+).
 */

$phantoms = [];
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    if (\function_exists($fn)) {
        $phantoms[] = $fn;
    }
}

if ([] !== $phantoms) {
    echo 'fail: '.implode(', ', $phantoms).' registered on Zend 8.2 reference profile';
    exit(1);
}

echo "ok\n";
