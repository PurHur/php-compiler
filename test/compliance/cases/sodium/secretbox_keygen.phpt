--TEST--
sodium_crypto_secretbox_keygen() returns 32-byte key for secretbox roundtrip (#18314)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox_keygen')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_secretbox_keygen();
echo (SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen($key)) ? "key_len_ok\n" : "key_len_fail\n";
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox('roundtrip', $nonce, $key);
$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
echo ($plain === 'roundtrip') ? "roundtrip_ok\n" : "roundtrip_fail\n";
--EXPECT--
key_len_ok
roundtrip_ok
