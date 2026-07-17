--TEST--
sodium_crypto_shorthash + sodium_crypto_kdf_derive_from_key (#20063)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!function_exists('sodium_crypto_shorthash') || !function_exists('sodium_crypto_kdf_derive_from_key')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_shorthash_keygen();
echo (SODIUM_CRYPTO_SHORTHASH_KEYBYTES === strlen($key)) ? "sh_key_ok\n" : "sh_key_fail\n";
$hash = sodium_crypto_shorthash('fingerprint', $key);
echo (SODIUM_CRYPTO_SHORTHASH_BYTES === strlen($hash)) ? "sh_len_ok\n" : "sh_len_fail\n";

$master = sodium_crypto_kdf_keygen();
echo (SODIUM_CRYPTO_KDF_KEYBYTES === strlen($master)) ? "kdf_key_ok\n" : "kdf_key_fail\n";
$sub = sodium_crypto_kdf_derive_from_key(32, 0, 'context_', $master);
echo (32 === strlen($sub)) ? "kdf_sub_ok\n" : "kdf_sub_fail\n";
$a = sodium_crypto_kdf_derive_from_key(32, 0, 'context_', $master);
$b = sodium_crypto_kdf_derive_from_key(32, 1, 'context_', $master);
echo ($a === $b) ? "kdf_id_same\n" : "kdf_id_diff\n";

$bad = false;
try {
    sodium_crypto_kdf_derive_from_key(32, 0, 'short', $master);
} catch (SodiumException $e) {
    $bad = true;
}
echo $bad ? "kdf_ctx_err\n" : "kdf_ctx_ok\n";
--EXPECT--
sh_key_ok
sh_len_ok
kdf_key_ok
kdf_sub_ok
kdf_id_diff
kdf_ctx_err
