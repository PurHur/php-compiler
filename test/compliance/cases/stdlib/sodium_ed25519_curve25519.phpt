--TEST--
stdlib sodium_crypto_sign_ed25519_{sk,pk}_to_curve25519 (#20573)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_ed25519_sk_to_curve25519')) {
    echo "missing\n";
    exit(0);
}
$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$pk = sodium_crypto_sign_publickey($kp);
$csk = sodium_crypto_sign_ed25519_sk_to_curve25519($sk);
$cpk = sodium_crypto_sign_ed25519_pk_to_curve25519($pk);
echo (strlen($csk) === SODIUM_CRYPTO_BOX_SECRETKEYBYTES) ? "sk_ok\n" : "sk_fail\n";
echo (strlen($cpk) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) ? "pk_ok\n" : "pk_fail\n";
try {
    sodium_crypto_sign_ed25519_sk_to_curve25519('x');
    echo "sk_throw_fail\n";
} catch (SodiumException $e) {
    echo "sk_throw_ok\n";
}
--EXPECT--
sk_ok
pk_ok
sk_throw_ok
