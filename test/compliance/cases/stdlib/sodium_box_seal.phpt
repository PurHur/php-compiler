--TEST--
stdlib sodium_crypto_box_seal() roundtrip (#15515)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_box_seal')) {
    echo "missing\n";
    exit(0);
}
$kp = sodium_crypto_box_keypair();
$pk = sodium_crypto_box_publickey($kp);
$ct = sodium_crypto_box_seal('secret', $pk);
$pt = sodium_crypto_box_seal_open($ct, $kp);
echo $pt === 'secret' ? "ok\n" : "fail\n";
echo \strlen($pk) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ? "pk_ok\n" : "pk_fail\n";
--EXPECT--
ok
pk_ok
