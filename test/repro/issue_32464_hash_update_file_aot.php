<?php
$path = '/tmp/phpc_huf_32464.txt';
file_put_contents($path, "hello\n");
$ctx = hash_init('sha256');
echo hash_update_file($ctx, $path) ? "updated\n" : "fail\n";
$actual = hash_final($ctx);
$expected = hash_file('sha256', $path);
echo $actual, "\n";
echo $actual === $expected ? "match\n" : "mismatch\n";
