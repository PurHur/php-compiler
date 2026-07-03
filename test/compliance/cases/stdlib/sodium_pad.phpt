--TEST--
stdlib sodium_pad()/sodium_unpad() roundtrip (#15532)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium') || !function_exists('sodium_pad')) {
    echo "missing\n";
    exit(0);
}
$msg = 'hello world';
$block = 16;
$padded = sodium_pad($msg, $block);
$unpadded = sodium_unpad($padded, $block);
echo $unpadded === $msg ? "ok\n" : "fail\n";
echo \strlen($padded) % $block === 0 ? "aligned\n" : "unaligned\n";
--EXPECT--
ok
aligned
