<?php

declare(strict_types=1);

/**
 * Repro for #28086 / #20518 — AEGIS AEAD registration gated on libsodium symbols
 * (php-src #ifdef crypto_aead_aegis{128l,256}_KEYBYTES), not host Zend wrappers alone.
 *
 * Default (libsodium 1.0.18): all aegis* lines = 0 while extension_loaded(sodium)=1.
 * With PHP_COMPILER_LIBSODIUM_SO → libsodium ≥ 1.0.19 that exports AEGIS: all = 1.
 */
echo 'extension_loaded=', extension_loaded('sodium') ? '1' : '0', "\n";

$funcs = [
    'sodium_crypto_aead_aegis128l_encrypt',
    'sodium_crypto_aead_aegis128l_decrypt',
    'sodium_crypto_aead_aegis128l_keygen',
    'sodium_crypto_aead_aegis256_encrypt',
    'sodium_crypto_aead_aegis256_decrypt',
    'sodium_crypto_aead_aegis256_keygen',
    'sodium_crypto_aead_aes256gcm_encrypt',
];
foreach ($funcs as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$consts = [
    'SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES',
    'SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES',
];
foreach ($consts as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}

if (function_exists('sodium_crypto_aead_aegis128l_encrypt')) {
    $key = sodium_crypto_aead_aegis128l_keygen();
    $npub = random_bytes(SODIUM_CRYPTO_AEAD_AEGIS128L_NPUBBYTES);
    $ct = sodium_crypto_aead_aegis128l_encrypt('secret', 'meta', $npub, $key);
    $pt = sodium_crypto_aead_aegis128l_decrypt($ct, 'meta', $npub, $key);
    echo 'roundtrip128l=', ($pt === 'secret') ? '1' : '0', "\n";
} else {
    echo "roundtrip128l=skip\n";
}

if (function_exists('sodium_crypto_aead_aegis256_encrypt')) {
    $key2 = sodium_crypto_aead_aegis256_keygen();
    $npub2 = random_bytes(SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES);
    $ct2 = sodium_crypto_aead_aegis256_encrypt('secret', 'meta', $npub2, $key2);
    $pt2 = sodium_crypto_aead_aegis256_decrypt($ct2, 'meta', $npub2, $key2);
    echo 'roundtrip256=', ($pt2 === 'secret') ? '1' : '0', "\n";
} else {
    echo "roundtrip256=skip\n";
}
