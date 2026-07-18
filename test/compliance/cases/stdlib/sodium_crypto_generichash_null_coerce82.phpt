--TEST--
stdlib sodium_crypto_generichash() null still coerces on 8.2 profile (#20696, ext/sodium/libsodium.c)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo bin2hex(sodium_crypto_generichash(null)), "\n";
echo bin2hex(sodium_crypto_generichash('p', null)), "\n";
?>
--EXPECT--
0e5751c026e543b2e8ab2eb06099daa1d1e5df47778f7787faab45cdf12fe3a8
b38a5a6a42b08c4e1f93e6051acff64c9e40a0bfc43c71e06072195a84e1cc3d
