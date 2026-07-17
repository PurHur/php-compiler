<?php
declare(strict_types=1);

/**
 * Repro for #20031 — sodium_crypto_aead_chacha20poly1305(_ietf)_* (VM).
 */
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable\n");
    exit(0);
}

$fns = [
    'sodium_crypto_aead_chacha20poly1305_keygen',
    'sodium_crypto_aead_chacha20poly1305_encrypt',
    'sodium_crypto_aead_chacha20poly1305_decrypt',
    'sodium_crypto_aead_chacha20poly1305_ietf_keygen',
    'sodium_crypto_aead_chacha20poly1305_ietf_encrypt',
    'sodium_crypto_aead_chacha20poly1305_ietf_decrypt',
];
foreach ($fns as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "fail: {$fn}() not registered\n");
        exit(1);
    }
    echo $fn, "=Y\n";
}

$key = sodium_crypto_aead_chacha20poly1305_keygen();
$nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES);
$ct = sodium_crypto_aead_chacha20poly1305_encrypt('hi', 'ad', $nonce, $key);
$pt = sodium_crypto_aead_chacha20poly1305_decrypt($ct, 'ad', $nonce, $key);
echo 'roundtrip=', ($pt === 'hi') ? 'OK' : 'FAIL', "\n";
$bad = $ct;
$bad[0] = chr(ord($bad[0]) ^ 0xff);
echo 'tamper=', (false === sodium_crypto_aead_chacha20poly1305_decrypt($bad, 'ad', $nonce, $key)) ? 'OK' : 'FAIL', "\n";

$key2 = sodium_crypto_aead_chacha20poly1305_ietf_keygen();
$nonce2 = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);
$ct2 = sodium_crypto_aead_chacha20poly1305_ietf_encrypt('hi', 'ad', $nonce2, $key2);
$pt2 = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($ct2, 'ad', $nonce2, $key2);
echo 'ietf_roundtrip=', ($pt2 === 'hi') ? 'OK' : 'FAIL', "\n";
$bad2 = $ct2;
$bad2[0] = chr(ord($bad2[0]) ^ 0xff);
echo 'ietf_tamper=', (false === sodium_crypto_aead_chacha20poly1305_ietf_decrypt($bad2, 'ad', $nonce2, $key2)) ? 'OK' : 'FAIL', "\n";

try {
    sodium_crypto_aead_chacha20poly1305_encrypt('hi', 'ad', 'short', $key);
    echo "nonce_len_fail\n";
} catch (SodiumException $e) {
    echo "nonce_len_ok\n";
}
