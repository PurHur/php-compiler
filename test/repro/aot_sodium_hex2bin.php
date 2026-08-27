<?php
declare(strict_types=1);

/**
 * Repro for #35357 — sodium_hex2bin() AOT must match Zend (peer sodium_bin2hex #26871).
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_hex2bin)
 */
$hex = '61' . '62';
echo sodium_hex2bin($hex), "\n";
echo sodium_hex2bin('61:62', ':'), "\n";
try {
    sodium_hex2bin('xyz');
    echo "no_throw\n";
} catch (SodiumException $e) {
    echo "ex\n";
}
