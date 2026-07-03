<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_crypto_scalarmult_base')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_scalarmult_base unavailable\n");
    exit(0);
}

$n = hex2bin('5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb');
$p = sodium_crypto_scalarmult_base($n);
$q = sodium_crypto_scalarmult($n, $p);

echo function_exists('sodium_crypto_scalarmult_base') ? "base_exists\n" : "base_missing\n";
echo function_exists('sodium_crypto_scalarmult') ? "mult_exists\n" : "mult_missing\n";
echo \strlen($p) === SODIUM_CRYPTO_SCALARMULT_BYTES ? "base_len_ok\n" : "base_len_fail\n";
echo \strlen($q) === SODIUM_CRYPTO_SCALARMULT_BYTES ? "mult_len_ok\n" : "mult_len_fail\n";
