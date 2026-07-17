<?php

declare(strict_types=1);

/**
 * Maintainer gap repro for #20082 — sodium_crypto_auth_keygen.
 *
 * Zend: keygen exists; key length = SODIUM_CRYPTO_AUTH_KEYBYTES; auth/verify round-trip.
 * VM (before fix): function_exists keygen = 0.
 */
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_auth')) {
    fwrite(STDERR, "skip: ext/sodium or sodium_crypto_auth unavailable\n");
    exit(0);
}

echo 'auth=', function_exists('sodium_crypto_auth') ? '1' : '0', "\n";
echo 'keygen=', function_exists('sodium_crypto_auth_keygen') ? '1' : '0', "\n";
if (!function_exists('sodium_crypto_auth_keygen')) {
    exit(0);
}

$key = sodium_crypto_auth_keygen();
echo 'klen=', strlen($key), "\n";
echo 'klen_ok=', (SODIUM_CRYPTO_AUTH_KEYBYTES === strlen($key)) ? '1' : '0', "\n";
$mac = sodium_crypto_auth('msg', $key);
echo 'ok=', sodium_crypto_auth_verify($mac, 'msg', $key) ? '1' : '0', "\n";
echo 'bad=', sodium_crypto_auth_verify($mac, 'wrong', $key) ? '1' : '0', "\n";
