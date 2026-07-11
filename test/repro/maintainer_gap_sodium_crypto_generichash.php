<?php
declare(strict_types=1);

if (!extension_loaded('sodium') || !function_exists('sodium_crypto_generichash')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_generichash unavailable\n");
    exit(0);
}

$msg = 'test message';
$hash = sodium_crypto_generichash($msg);
$key = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
$keyed = sodium_crypto_generichash($msg, $key);
$short = sodium_crypto_generichash($msg, '', 16);

echo function_exists('sodium_crypto_generichash') ? "exists\n" : "missing\n";
echo \strlen($hash) === SODIUM_CRYPTO_GENERICHASH_BYTES ? "default_len_ok\n" : "default_len_fail\n";
echo \strlen($keyed) === SODIUM_CRYPTO_GENERICHASH_BYTES ? "keyed_len_ok\n" : "keyed_len_fail\n";
echo \strlen($short) === 16 ? "short_len_ok\n" : "short_len_fail\n";
echo $hash !== $keyed ? "keyed_diff_ok\n" : "keyed_diff_fail\n";
