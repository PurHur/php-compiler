<?php
declare(strict_types=1);
// Maintainer gap repro: sodium AES-256-GCM AEAD (#15542).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_aead_aes256gcm_is_available',
    'sodium_crypto_aead_aes256gcm_encrypt',
    'sodium_crypto_aead_aes256gcm_decrypt',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "{$fn} not registered\n");
        exit(1);
    }
}

if (!sodium_crypto_aead_aes256gcm_is_available()) {
    fwrite(STDERR, "skip: AES-256-GCM not available on host\n");
    exit(0);
}

$key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
$npub = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
$msg = 'secret payload';
$ad = 'header metadata';
$ct = sodium_crypto_aead_aes256gcm_encrypt($msg, $ad, $npub, $key);
$pt = sodium_crypto_aead_aes256gcm_decrypt($ct, $ad, $npub, $key);
if ($pt !== $msg) {
    fwrite(STDERR, "aead round-trip failed\n");
    exit(1);
}

$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
if (false !== sodium_crypto_aead_aes256gcm_decrypt($bad, $ad, $npub, $key)) {
    fwrite(STDERR, "tampered ciphertext should fail\n");
    exit(1);
}

echo "aead_aes256gcm_ok\n";
