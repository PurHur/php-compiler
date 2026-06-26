<?php

declare(strict_types=1);

/**
 * Maintainer repro: getmygrgid() must not exist on Zend 8.2 reference profile (issue #11923).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getmygid); no getmygrgid.
 */

if (function_exists('getmygrgid')) {
    echo "fail: getmygrgid phantom registered\n";
    exit(1);
}

if (!function_exists('getmygid')) {
    echo "fail: getmygid missing\n";
    exit(1);
}

echo "ok: getmygrgid not registered\n";
