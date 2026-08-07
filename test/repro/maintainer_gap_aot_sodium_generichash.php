<?php
/**
 * Repro for #27292 — sodium_crypto_generichash() AOT must match Zend/VM/JIT.
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_crypto_generichash)
 *
 * Note: thin AOT standalone may not advertise extension_loaded('sodium'); call the
 * builtin directly (same pattern as maintainer_gap_aot_sodium_bin2hex.php).
 */
echo bin2hex(sodium_crypto_generichash('hi')), "\n";
