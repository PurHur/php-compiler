<?php
declare(strict_types=1);

/**
 * #35357 — AOT sodium_hex2bin() must match Zend (peer sodium_bin2hex NestedJIT).
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_hex2bin)
 */
echo sodium_hex2bin('6162'), "\n";
echo sodium_hex2bin('61:62', ':'), "\n";
$h = '616263';
echo sodium_hex2bin($h), "\n";
$ign = ':';
echo sodium_hex2bin('61:62', $ign), "\n";
try {
    sodium_hex2bin('xyz');
    echo "no_throw\n";
} catch (SodiumException $e) {
    echo 'ex=', (str_contains($e->getMessage(), 'valid hexadecimal') ? '1' : '0'), "\n";
}
