--TEST--
AOT: sodium_crypto_generichash() matches Zend BLAKE2b digest (#27292)
--SKIPIF--
<?php if (!extension_loaded('sodium') || !function_exists('sodium_crypto_generichash')) { die('skip ext/sodium generichash unavailable'); } ?>
--FILE--
<?php
echo bin2hex(sodium_crypto_generichash('hi')), PHP_EOL;
$key = str_repeat('k', SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
echo bin2hex(sodium_crypto_generichash('hi', $key)), PHP_EOL;
echo bin2hex(sodium_crypto_generichash('hi', '', 16)), PHP_EOL;
?>
--EXPECT--
6815cb4aeb1580a91ef673e63ff03bdb6e855c3a896db3f2765e03281a61134a
6fa1b79636b9bb33987ebe41aa22743d07d0a6c7d30a7f32997017278f44adb2
8cc39ac9c664b3691c9d12d36ae55577
