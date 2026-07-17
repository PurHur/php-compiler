--TEST--
stdlib sodium_crypto_box() roundtrip (#20026)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_box')) {
    echo "missing\n";
    exit(0);
}
$alice = sodium_crypto_box_keypair();
$bob = sodium_crypto_box_keypair();
$alice_sk = sodium_crypto_box_secretkey($alice);
$alice_pk = sodium_crypto_box_publickey($alice);
$bob_sk = sodium_crypto_box_secretkey($bob);
$bob_pk = sodium_crypto_box_publickey($bob);
$send = sodium_crypto_box_keypair_from_secretkey_and_publickey($alice_sk, $bob_pk);
$recv = sodium_crypto_box_keypair_from_secretkey_and_publickey($bob_sk, $alice_pk);
$nonce = str_repeat("\0", SODIUM_CRYPTO_BOX_NONCEBYTES);
$ct = sodium_crypto_box('hi', $nonce, $send);
$pt = sodium_crypto_box_open($ct, $nonce, $recv);
echo $pt === 'hi' ? "ok\n" : "fail\n";
echo sodium_crypto_box_publickey_from_secretkey($alice_sk) === $alice_pk ? "pk_ok\n" : "pk_fail\n";
echo false === sodium_crypto_box_open($ct, $nonce, $alice) ? "false_ok\n" : "false_fail\n";
--EXPECT--
ok
pk_ok
false_ok
