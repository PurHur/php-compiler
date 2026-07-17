--TEST--
sodium_crypto_auth_keygen() returns 32-byte key for auth roundtrip (#20082)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_auth_keygen')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_auth_keygen();
echo (SODIUM_CRYPTO_AUTH_KEYBYTES === strlen($key)) ? "key_len_ok\n" : "key_len_fail\n";
$mac = sodium_crypto_auth('roundtrip', $key);
echo sodium_crypto_auth_verify($mac, 'roundtrip', $key) ? "roundtrip_ok\n" : "roundtrip_fail\n";
echo sodium_crypto_auth_verify($mac, 'wrong', $key) ? "tamper_ok\n" : "tamper_reject\n";
--EXPECT--
key_len_ok
roundtrip_ok
tamper_reject
