<?php

declare(strict_types=1);

/**
 * Repro for #20062 — sodium_crypto_generichash_init/update/final/keygen streaming.
 */
echo 'init=', function_exists('sodium_crypto_generichash_init') ? 1 : 0, "\n";
echo 'update=', function_exists('sodium_crypto_generichash_update') ? 1 : 0, "\n";
echo 'final=', function_exists('sodium_crypto_generichash_final') ? 1 : 0, "\n";
echo 'keygen=', function_exists('sodium_crypto_generichash_keygen') ? 1 : 0, "\n";
if (!function_exists('sodium_crypto_generichash_init')) {
    exit(0);
}

$st = sodium_crypto_generichash_init();
sodium_crypto_generichash_update($st, 'ab');
sodium_crypto_generichash_update($st, 'cd');
echo 'match=', (sodium_crypto_generichash_final($st) === sodium_crypto_generichash('abcd')) ? 1 : 0, "\n";

$key = sodium_crypto_generichash_keygen();
$st2 = sodium_crypto_generichash_init($key);
sodium_crypto_generichash_update($st2, 'ab');
sodium_crypto_generichash_update($st2, 'cd');
echo 'keyed_match=', (sodium_crypto_generichash_final($st2) === sodium_crypto_generichash('abcd', $key)) ? 1 : 0, "\n";
echo 'key_len=', strlen($key) === SODIUM_CRYPTO_GENERICHASH_KEYBYTES ? 1 : 0, "\n";
