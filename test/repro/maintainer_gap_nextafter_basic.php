<?php

declare(strict_types=1);

/**
 * Maintainer repro: nextafter() must exist on PHP 8.4+ profile (#9241).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */

if (!\function_exists('nextafter')) {
    echo "skip: nextafter not registered on reference profile\n";
    exit(0);
}

$towardInf = nextafter(1.0, 2.0);
$towardZero = nextafter(1.0, 0.0);
if (1.0000000000000002 !== $towardInf) {
    echo "fail: toward +inf expected 1.0000000000000002 got {$towardInf}\n";
    exit(1);
}
if ($towardZero >= 1.0 || $towardZero <= 0.0) {
    echo "fail: toward 0.0 expected (0,1) got {$towardZero}\n";
    exit(1);
}

echo "ok\n";
