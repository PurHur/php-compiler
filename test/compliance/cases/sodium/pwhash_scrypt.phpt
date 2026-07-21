--TEST--
sodium_crypto_pwhash_scryptsalsa208sha256* derive + str verify (#21460)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
$fns = [
    'sodium_crypto_pwhash_scryptsalsa208sha256',
    'sodium_crypto_pwhash_scryptsalsa208sha256_str',
    'sodium_crypto_pwhash_scryptsalsa208sha256_str_verify',
];
foreach ($fns as $f) {
    if (!function_exists($f)) {
        echo "missing\n";
        exit(0);
    }
}
echo (SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES === 32) ? "salt_ok\n" : "salt_fail\n";
echo (SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRPREFIX === '$7$') ? "prefix_ok\n" : "prefix_fail\n";

$hash = sodium_crypto_pwhash_scryptsalsa208sha256_str(
    'password',
    SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
);
echo (str_starts_with($hash, SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRPREFIX)) ? "hash_prefix_ok\n" : "hash_prefix_fail\n";
echo sodium_crypto_pwhash_scryptsalsa208sha256_str_verify($hash, 'password') ? "verify_ok\n" : "verify_fail\n";
echo sodium_crypto_pwhash_scryptsalsa208sha256_str_verify($hash, 'wrong') ? "verify_bad_ok\n" : "verify_bad_fail\n";

$salt = str_repeat("\0", SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES);
$derived = sodium_crypto_pwhash_scryptsalsa208sha256(
    16,
    'password',
    $salt,
    SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE,
    SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
);
echo (16 === strlen($derived)) ? "derive_ok\n" : "derive_fail\n";

$bad = false;
try {
    sodium_crypto_pwhash_scryptsalsa208sha256(
        16,
        'password',
        'short',
        SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
    );
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
derive_ok
salt_err
