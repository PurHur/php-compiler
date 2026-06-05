--TEST--
AOT: fsync() on writable handle (#6062)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_fsync.txt';
$fp = fopen($path, 'w');
echo fsync($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
