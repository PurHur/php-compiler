<?php
$path = '/tmp/phpc_hus_32483_len.txt';
file_put_contents($path, 'hello world');
$h = fopen($path, 'rb');
$ctx = hash_init('sha256');
$n = hash_update_stream($ctx, $h, 5);
echo "partial-bytes=$n\n";
echo hash_final($ctx), "\n";
