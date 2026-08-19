--TEST--
AOT hash_update_stream() length-limited SHA-256 from file handle (#32483, ext/hash/hash.c)
--FILE--
<?php
$path = '/tmp/phpc_hus_32483_len_aot.txt';
file_put_contents($path, 'hello world');
$h = fopen($path, 'rb');
$ctx = hash_init('sha256');
$n = hash_update_stream($ctx, $h, 5);
echo "partial-bytes=$n\n";
echo hash_final($ctx), "\n";
--EXPECT--
partial-bytes=5
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
