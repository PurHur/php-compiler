--TEST--
stdlib sodium_crypto_aead_xchacha20poly1305_ietf_* AEAD roundtrip (#15429)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
    echo "missing\n";
    exit(0);
}
$key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$npub = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$msg = 'secret';
$ad = 'meta';
$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($msg, $ad, $npub, $key);
$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $ad, $npub, $key);
echo ($pt === $msg) ? "roundtrip_ok\n" : "roundtrip_fail\n";
$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
echo (false === sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($bad, $ad, $npub, $key)) ? "tamper_fail_ok\n" : "tamper_fail_bad\n";
--EXPECT--
roundtrip_ok
tamper_fail_ok
