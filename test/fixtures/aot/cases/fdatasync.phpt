--TEST--
AOT: fdatasync() on writable handle (#6813)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_fdatasync.txt';
$fp = fopen($path, 'w');
echo fdatasync($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
