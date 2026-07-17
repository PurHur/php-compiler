--TEST--
sodium_crypto_pwhash + pwhash_str verify/needs_rehash (#20048)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!function_exists('sodium_crypto_pwhash') || !function_exists('sodium_crypto_pwhash_str')) {
    echo "missing\n";
    exit(0);
}
echo (SODIUM_CRYPTO_PWHASH_SALTBYTES === 16) ? "salt_ok\n" : "salt_fail\n";
echo (SODIUM_CRYPTO_PWHASH_STRPREFIX === '$argon2id$') ? "prefix_ok\n" : "prefix_fail\n";

$hash = sodium_crypto_pwhash_str(
    'password',
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
);
echo (str_starts_with($hash, SODIUM_CRYPTO_PWHASH_STRPREFIX)) ? "hash_prefix_ok\n" : "hash_prefix_fail\n";
echo sodium_crypto_pwhash_str_verify($hash, 'password') ? "verify_ok\n" : "verify_fail\n";
echo sodium_crypto_pwhash_str_verify($hash, 'wrong') ? "verify_bad_ok\n" : "verify_bad_fail\n";
echo sodium_crypto_pwhash_str_needs_rehash(
    $hash,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
) ? "rehash_same_yes\n" : "rehash_same_no\n";
echo sodium_crypto_pwhash_str_needs_rehash(
    $hash,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
) ? "rehash_diff_yes\n" : "rehash_diff_no\n";

$salt = str_repeat("\0", SODIUM_CRYPTO_PWHASH_SALTBYTES);
$derived = sodium_crypto_pwhash(
    16,
    'password',
    $salt,
    SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
);
echo (16 === strlen($derived)) ? "derive_ok\n" : "derive_fail\n";

$bad = false;
try {
    sodium_crypto_pwhash(16, 'password', 'short', SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
} catch (SodiumException $e) {
    $bad = true;
}
echo $bad ? "salt_err\n" : "salt_ok_bad\n";
--EXPECT--
salt_ok
prefix_ok
hash_prefix_ok
verify_ok
verify_bad_fail
rehash_same_no
rehash_diff_yes
derive_ok
salt_err
