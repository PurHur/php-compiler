--TEST--
stdlib sodium_crypto_scalarmult_base()/scalarmult() (#15516)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_scalarmult_base')) {
    echo "missing\n";
    exit(0);
}
$n = hex2bin('5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb');
$p = sodium_crypto_scalarmult_base($n);
$q = sodium_crypto_scalarmult($n, $p);
echo \strlen($p) === SODIUM_CRYPTO_SCALARMULT_BYTES ? "base_ok\n" : "base_fail\n";
echo \strlen($q) === SODIUM_CRYPTO_SCALARMULT_BYTES ? "mult_ok\n" : "mult_fail\n";
--EXPECT--
base_ok
mult_ok
