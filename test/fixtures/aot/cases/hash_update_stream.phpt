--TEST--
AOT hash_update_stream() incremental SHA-256 from file handle (#32483, ext/hash/hash.c)
--FILE--
<?php
$path = '/tmp/phpc_hus_32483_aot.txt';
file_put_contents($path, 'hello world');
$h = fopen($path, 'rb');
$ctx = hash_init('sha256');
$n = hash_update_stream($ctx, $h);
echo "bytes=$n\n";
echo hash_final($ctx), "\n";
--EXPECT--
bytes=11
b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9
