--TEST--
stdlib sodium_crypto_aead_chacha20poly1305(_ietf)_* AEAD roundtrip (#20031)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_aead_chacha20poly1305_encrypt')) {
    echo "missing\n";
    exit(0);
}
$key = sodium_crypto_aead_chacha20poly1305_keygen();
$npub = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES);
$msg = 'secret';
$ad = 'meta';
$ct = sodium_crypto_aead_chacha20poly1305_encrypt($msg, $ad, $npub, $key);
$pt = sodium_crypto_aead_chacha20poly1305_decrypt($ct, $ad, $npub, $key);
echo ($pt === $msg) ? "roundtrip_ok\n" : "roundtrip_fail\n";
$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
echo (false === sodium_crypto_aead_chacha20poly1305_decrypt($bad, $ad, $npub, $key)) ? "tamper_fail_ok\n" : "tamper_fail_bad\n";

$key2 = sodium_crypto_aead_chacha20poly1305_ietf_keygen();
$npub2 = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
$ct2 = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($msg, $ad, $npub2, $key2);
$pt2 = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($ct2, $ad, $npub2, $key2);
echo ($pt2 === $msg) ? "ietf_roundtrip_ok\n" : "ietf_roundtrip_fail\n";
$bad2 = $ct2;
$bad2[0] = chr(ord($bad2[0]) ^ 0xff);
echo (false === sodium_crypto_aead_chacha20poly1305_ietf_decrypt($bad2, $ad, $npub2, $key2)) ? "ietf_tamper_fail_ok\n" : "ietf_tamper_fail_bad\n";
--EXPECT--
roundtrip_ok
tamper_fail_ok
ietf_roundtrip_ok
ietf_tamper_fail_ok
