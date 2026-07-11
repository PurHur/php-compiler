--TEST--
stdlib sodium_crypto_aead_aes256gcm_* AEAD roundtrip (#15542)
--SKIPIF--
<?php if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_aes256gcm_is_available') || !sodium_crypto_aead_aes256gcm_is_available()) { die('skip AES-256-GCM unavailable'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
    echo "missing\n";
    exit(0);
}
$key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
$npub = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
$msg = 'secret';
$ad = 'meta';
$ct = sodium_crypto_aead_aes256gcm_encrypt($msg, $ad, $npub, $key);
$pt = sodium_crypto_aead_aes256gcm_decrypt($ct, $ad, $npub, $key);
echo ($pt === $msg) ? "roundtrip_ok\n" : "roundtrip_fail\n";
$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
echo (false === sodium_crypto_aead_aes256gcm_decrypt($bad, $ad, $npub, $key)) ? "tamper_fail_ok\n" : "tamper_fail_bad\n";
--EXPECT--
roundtrip_ok
tamper_fail_ok
