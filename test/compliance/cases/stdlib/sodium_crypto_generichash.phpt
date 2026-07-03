--TEST--
stdlib sodium_crypto_generichash() default and keyed (#15530)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_crypto_generichash')) {
    echo "missing\n";
    exit(0);
}
$msg = 'test message';
$hash = sodium_crypto_generichash($msg);
$key = random_bytes(SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
$keyed = sodium_crypto_generichash($msg, $key);
echo \strlen($hash) === SODIUM_CRYPTO_GENERICHASH_BYTES ? "default_ok\n" : "default_fail\n";
echo \strlen($keyed) === SODIUM_CRYPTO_GENERICHASH_BYTES ? "keyed_ok\n" : "keyed_fail\n";
echo $hash !== $keyed ? "diff_ok\n" : "diff_fail\n";
--EXPECT--
default_ok
keyed_ok
diff_ok
