<?php
declare(strict_types=1);

/**
 * Repro for #20063 — sodium_crypto_shorthash* + sodium_crypto_kdf_*.
 * Compare: php test/repro/maintainer_gap_sodium_shorthash_kdf.php
 *      vs: php bin/vm.php test/repro/maintainer_gap_sodium_shorthash_kdf.php
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable on host reference\n");
    // Still probe VM registration when run under bin/vm.php
}

foreach ([
    'sodium_crypto_shorthash',
    'sodium_crypto_shorthash_keygen',
    'sodium_crypto_kdf_keygen',
    'sodium_crypto_kdf_derive_from_key',
] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

if (!function_exists('sodium_crypto_shorthash') || !function_exists('sodium_crypto_kdf_derive_from_key')) {
    echo "missing\n";
    exit(0);
}

$key = sodium_crypto_shorthash_keygen();
echo 'shorthash_key_len=', strlen($key), "\n";
$hash = sodium_crypto_shorthash('msg', $key);
echo 'shorthash_len=', strlen($hash), "\n";
echo 'shorthash_const=', (SODIUM_CRYPTO_SHORTHASH_BYTES === strlen($hash)) ? 1 : 0, "\n";

$master = sodium_crypto_kdf_keygen();
echo 'kdf_key_len=', strlen($master), "\n";
$sub = sodium_crypto_kdf_derive_from_key(32, 0, 'context_', $master);
echo 'kdf_sub_len=', strlen($sub), "\n";
$sub16 = sodium_crypto_kdf_derive_from_key(16, 1, 'context_', $master);
echo 'kdf_sub16_len=', strlen($sub16), "\n";

try {
    sodium_crypto_shorthash('msg', 'short');
    echo "shorthash_bad_key=ok\n";
} catch (Throwable $e) {
    echo 'shorthash_bad_key=', $e::class, "\n";
}

try {
    sodium_crypto_kdf_derive_from_key(32, 0, 'ctx', $master);
    echo "kdf_bad_ctx=ok\n";
} catch (Throwable $e) {
    echo 'kdf_bad_ctx=', $e::class, "\n";
}

try {
    sodium_crypto_kdf_derive_from_key(8, 0, 'context_', $master);
    echo "kdf_short_len=ok\n";
} catch (Throwable $e) {
    echo 'kdf_short_len=', $e::class, "\n";
}

echo "pass\n";
