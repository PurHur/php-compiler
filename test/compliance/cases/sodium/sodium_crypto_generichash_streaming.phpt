--TEST--
sodium sodium_crypto_generichash_init/update/final/keygen (#20062)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!function_exists('sodium_crypto_generichash_init')) {
    echo "missing\n";
    exit(0);
}
$st = sodium_crypto_generichash_init();
sodium_crypto_generichash_update($st, 'ab');
sodium_crypto_generichash_update($st, 'cd');
echo (sodium_crypto_generichash_final($st) === sodium_crypto_generichash('abcd')) ? "stream_ok\n" : "stream_fail\n";

$key = sodium_crypto_generichash_keygen();
$st2 = sodium_crypto_generichash_init($key);
sodium_crypto_generichash_update($st2, 'ab');
sodium_crypto_generichash_update($st2, 'cd');
echo (sodium_crypto_generichash_final($st2) === sodium_crypto_generichash('abcd', $key)) ? "keyed_ok\n" : "keyed_fail\n";
echo \strlen($key) === SODIUM_CRYPTO_GENERICHASH_KEYBYTES ? "keygen_ok\n" : "keygen_fail\n";

$st3 = sodium_crypto_generichash_init('', 16);
sodium_crypto_generichash_update($st3, 'x');
$short = sodium_crypto_generichash_final($st3, 16);
echo ($short === sodium_crypto_generichash('x', '', 16) && \strlen($short) === 16) ? "len16_ok\n" : "len16_fail\n";
--EXPECT--
stream_ok
keyed_ok
keygen_ok
len16_ok
