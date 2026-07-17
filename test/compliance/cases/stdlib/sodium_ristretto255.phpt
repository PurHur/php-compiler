--TEST--
stdlib sodium ristretto255 core + scalarmult_* (#20084)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_core_ristretto255_random')) {
    echo "missing\n";
    exit(0);
}
$p = sodium_crypto_core_ristretto255_random();
echo sodium_crypto_core_ristretto255_is_valid_point($p) ? "random_valid\n" : "random_invalid\n";
$s = sodium_crypto_core_ristretto255_scalar_random();
$q = sodium_crypto_scalarmult_ristretto255_base($s);
echo sodium_crypto_core_ristretto255_is_valid_point($q) ? "base_valid\n" : "base_invalid\n";
$h = random_bytes(SODIUM_CRYPTO_CORE_RISTRETTO255_HASHBYTES);
$from = sodium_crypto_core_ristretto255_from_hash($h);
$a = sodium_crypto_core_ristretto255_add($p, $from);
$b = sodium_crypto_core_ristretto255_sub($a, $from);
echo ($b === $p) ? "add_sub_ok\n" : "add_sub_fail\n";
$sm = sodium_crypto_scalarmult_ristretto255($s, $p);
echo sodium_crypto_core_ristretto255_is_valid_point($sm) ? "scalarmult_ok\n" : "scalarmult_fail\n";
--EXPECT--
random_valid
base_valid
add_sub_ok
scalarmult_ok
