<?php
/**
 * Repro for #27318 — AOT sodium_crypto_aead_xchacha20poly1305_ietf_{encrypt,decrypt}.
 *
 * Use non-NUL key/nonce bytes: AOT str_repeat("\\0", N) currently yields a bad
 * data pointer (strlen OK, byte access segfaults) — avoid that independent defect.
 */
$key = str_repeat('K', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$nonce = str_repeat('N', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
$ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('hi', 'ad', $nonce, $key);
$pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, 'ad', $nonce, $key);
var_export($pt);
echo "\n";
