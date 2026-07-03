--TEST--
stdlib sodium_crypto_sign* Ed25519 roundtrip (#15541)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_keypair')) {
    echo "missing\n";
    exit(0);
}
$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$pk = sodium_crypto_sign_publickey($kp);
$msg = 'payload';
$signed = sodium_crypto_sign($msg, $sk);
$opened = sodium_crypto_sign_open($signed, $pk);
echo ($opened === $msg) ? "sign_open_ok\n" : "sign_open_fail\n";
$sig = sodium_crypto_sign_detached($msg, $sk);
echo sodium_crypto_sign_verify_detached($sig, $msg, $pk) ? "verify_ok\n" : "verify_fail\n";
$badPk = $pk;
$badPk[0] = chr(ord($badPk[0]) ^ 0xff);
echo (false === sodium_crypto_sign_open($signed, $badPk)) ? "bad_open_ok\n" : "bad_open_fail\n";
--EXPECT--
sign_open_ok
verify_ok
bad_open_ok
