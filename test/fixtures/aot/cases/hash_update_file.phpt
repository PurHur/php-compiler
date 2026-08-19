--TEST--
AOT hash_update_file() incremental SHA-256 from path (#32464, ext/hash/hash.c)
--FILE--
<?php
$path = '/tmp/phpc_huf_32464_aot.txt';
file_put_contents($path, "hello\n");
$ctx = hash_init('sha256');
echo hash_update_file($ctx, $path) ? "updated\n" : "fail\n";
$actual = hash_final($ctx);
$expected = hash_file('sha256', $path);
echo $actual, "\n";
echo $actual === $expected ? "match\n" : "mismatch\n";
--EXPECT--
updated
5891b5b522d5df086d0ff0b110fbd9d21bb4fc7163af34d08286a2e846f6be03
match
