<?php
declare(strict_types=1);

/**
 * Repro for #35378 — sodium_bin2base64() AOT (leftover #20675).
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_bin2base64)
 */
$s = 'a' . 'b';
echo sodium_bin2base64($s, SODIUM_BASE64_VARIANT_ORIGINAL), "\n";
echo sodium_bin2base64($s, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING), "\n";
echo sodium_bin2base64($s, SODIUM_BASE64_VARIANT_URLSAFE), "\n";
try {
    sodium_bin2base64($s, 0);
    echo "no_throw\n";
} catch (SodiumException $e) {
    echo "ex\n";
}
