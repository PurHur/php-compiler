<?php
declare(strict_types=1);

/**
 * #24110 — SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX must be 64 (libsodium);
 * XCHACHA IETF_NSECBYTES + SECRETSTREAM MESSAGEBYTES_MAX must be defined.
 * php-src: ext/sodium/libsodium.stub.php
 */

if (!extension_loaded('sodium') && !function_exists('sodium_crypto_generichash')) {
    // VM registers sodium without host extension_loaded in some builds
}

echo 'KEYBYTES_MAX=' . (defined('SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX')
    ? (string) SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX
    : 'UNDEF') . "\n";

echo 'XCHACHA_NSECBYTES=' . (defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECBYTES')
    ? (string) SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECBYTES
    : 'UNDEF') . "\n";

echo 'SECRETSTREAM_MSGMAX=' . (defined('SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_MESSAGEBYTES_MAX')
    ? (string) SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_MESSAGEBYTES_MAX
    : 'UNDEF') . "\n";

$key64 = str_repeat('k', 64);
try {
    $h = sodium_crypto_generichash('m', $key64);
    echo 'hash64_hex=' . bin2hex($h) . "\n";
    echo 'hash64_len=' . strlen($h) . "\n";
} catch (Throwable $e) {
    echo 'hash64_err=' . $e->getMessage() . "\n";
}

// Over-max still rejected
try {
    sodium_crypto_generichash('m', str_repeat('k', 65));
    echo "overmax_ok_unexpected\n";
} catch (Throwable $e) {
    echo 'overmax_err=yes' . "\n";
}
