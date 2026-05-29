--TEST--
AOT: flock() LOCK_EX and LOCK_UN on fopen handle
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_flock.txt';
$fp = fopen($path, 'c+');
echo flock($fp, LOCK_EX) ? '1' : '0', "\n";
echo flock($fp, LOCK_UN) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
1
