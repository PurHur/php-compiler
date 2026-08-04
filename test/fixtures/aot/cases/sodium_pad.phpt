--TEST--
sodium_pad/sodium_unpad AOT roundtrip
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
$padded = sodium_pad('hi', 16);
echo \strlen($padded), '|', \bin2hex($padded), PHP_EOL;
echo sodium_unpad($padded, 16), PHP_EOL;
?>
--EXPECT--
16|68698000000000000000000000000000
hi
