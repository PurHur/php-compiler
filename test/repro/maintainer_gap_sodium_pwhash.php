<?php
declare(strict_types=1);

/**
 * Repro for #20048 — sodium_crypto_pwhash / pwhash_str / verify / needs_rehash.
 * Compare: php test/repro/maintainer_gap_sodium_pwhash.php
 *      vs: php bin/vm.php test/repro/maintainer_gap_sodium_pwhash.php
 */

foreach ([
    'sodium_crypto_pwhash',
    'sodium_crypto_pwhash_str',
    'sodium_crypto_pwhash_str_verify',
    'sodium_crypto_pwhash_str_needs_rehash',
] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}

if (!function_exists('sodium_crypto_pwhash_str') || !function_exists('sodium_crypto_pwhash')) {
    echo "MISSING\n";
    exit(0);
}

echo 'prefix=', SODIUM_CRYPTO_PWHASH_STRPREFIX, "\n";
echo 'saltbytes=', SODIUM_CRYPTO_PWHASH_SALTBYTES, "\n";

$hash = sodium_crypto_pwhash_str(
    'password',
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
);
echo 'verify=', sodium_crypto_pwhash_str_verify($hash, 'password') ? 1 : 0, "\n";
echo 'verify_bad=', sodium_crypto_pwhash_str_verify($hash, 'wrong') ? 1 : 0, "\n";
echo 'needs_rehash=', sodium_crypto_pwhash_str_needs_rehash(
    $hash,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
) ? 1 : 0, "\n";
echo 'needs_rehash_diff=', sodium_crypto_pwhash_str_needs_rehash(
    $hash,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
) ? 1 : 0, "\n";

$salt = str_repeat("\0", SODIUM_CRYPTO_PWHASH_SALTBYTES);
$derived = sodium_crypto_pwhash(
    16,
    'password',
    $salt,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
);
echo 'derive_len=', strlen($derived), "\n";

try {
    sodium_crypto_pwhash(16, 'password', 'short', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    echo "bad_salt=ok\n";
} catch (Throwable $e) {
    echo 'bad_salt=', $e::class, "\n";
}

echo "pass\n";
