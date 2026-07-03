<?php
declare(strict_types=1);
// Maintainer gap repro: sodium XChaCha20-Poly1305 AEAD (#15429).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
    'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "{$fn} not registered\n");
        exit(1);
    }
}

$key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$npub = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$msg = 'secret payload';
$ad = 'header metadata';
$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($msg, $ad, $npub, $key);
$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $ad, $npub, $key);
if ($pt !== $msg) {
    fwrite(STDERR, "aead round-trip failed\n");
    exit(1);
}

$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
if (false !== sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($bad, $ad, $npub, $key)) {
    fwrite(STDERR, "tampered ciphertext should fail\n");
    exit(1);
}

echo "aead_ok\n";
