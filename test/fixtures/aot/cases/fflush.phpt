--TEST--
AOT: fflush() on writable handle
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_fflush.txt';
$fp = fopen($path, 'w');
echo fflush($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
