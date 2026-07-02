<?php

declare(strict_types=1);

/**
 * Maintainer repro: sprintf() %h/%H must compile and match Zend (#9991).
 *
 * php-src: ext/standard/sprintf.c — %h uses general format with %F.
 */

$out = sprintf('%h', 1.2);
if ('1.2' !== $out) {
    echo "fail: %h 1.2 expected 1.2 got {$out}\n";
    exit(1);
}

$sci = sprintf('%H', 1234567.0);
if ('1.23457E+6' !== $sci) {
    echo "fail: %H large expected 1.23457E+6 got {$sci}\n";
    exit(1);
}

echo "ok\n";
