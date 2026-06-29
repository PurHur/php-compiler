--TEST--
stdlib extension_loaded('sodium') + sodium_crypto_secretbox roundtrip (#13078)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_secretbox')) {
    echo "missing\n";
    exit(0);
}
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox('hello', $nonce, $key);
$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
echo $plain, "\n";
--EXPECT--
hello
