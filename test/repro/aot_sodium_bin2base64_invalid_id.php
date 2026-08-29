<?php
declare(strict_types=1);

/**
 * #35378 follow-up — invalid variant id must throw catchable SodiumException under AOT.
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_bin2base64)
 */
try {
    sodium_bin2base64('ab', 0);
    echo "no_throw\n";
} catch (SodiumException $e) {
    echo 'ex=', (str_contains($e->getMessage(), 'valid base64 variant') ? '1' : '0'), "\n";
}
