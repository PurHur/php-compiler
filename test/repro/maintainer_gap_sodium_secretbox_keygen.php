<?php

declare(strict_types=1);

if (!function_exists('sodium_crypto_secretbox_keygen')) {
    fwrite(STDERR, "MISSING: sodium_crypto_secretbox_keygen\n");
    exit(1);
}

$key = sodium_crypto_secretbox_keygen();
if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
    fwrite(STDERR, "bad key length: ".strlen($key)."\n");
    exit(1);
}

$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox('probe', $nonce, $key);
$plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
if ('probe' !== $plain) {
    fwrite(STDERR, "roundtrip failed\n");
    exit(1);
}

echo "ok\n";
