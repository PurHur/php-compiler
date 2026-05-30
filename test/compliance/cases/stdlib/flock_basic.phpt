--TEST--
flock() exclusive and unlock on temp file (issue #3141)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_flock_basic_' . getmypid() . '.txt';
$fp = fopen($path, 'c+');
echo flock($fp, LOCK_EX) ? '1' : '0', "\n";
echo flock($fp, LOCK_UN) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
1
