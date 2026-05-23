--TEST--
AOT: ftell() on writable handle
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_ftell.txt';
$fp = fopen($path, 'w');
echo ftell($fp) === 0 ? '0' : 'n', "\n";
fwrite($fp, 'xy');
echo ftell($fp) === 2 ? '2' : 'n', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
0
2
