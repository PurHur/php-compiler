--TEST--
stdlib sodium_crypto_generichash() null TypeError on 8.4 forward (#20696, ext/sodium/libsodium.c)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = sodium_crypto_generichash(null);
    echo 'message uncaught ', bin2hex($r), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = sodium_crypto_generichash('p', null);
    echo 'key uncaught ', bin2hex($r), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo bin2hex(sodium_crypto_generichash('')), "\n";
?>
--EXPECT--
sodium_crypto_generichash(): Argument #1 ($message) must be of type string, null given
sodium_crypto_generichash(): Argument #2 ($key) must be of type string, null given
0e5751c026e543b2e8ab2eb06099daa1d1e5df47778f7787faab45cdf12fe3a8
