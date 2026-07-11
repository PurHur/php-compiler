--TEST--
flock() nested fopen() + LOCK_EX named constant (#9611, ext/standard/flock.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_flock_nested_' . getmypid() . '.txt';
file_put_contents($path, 'data');
echo flock(fopen($path, 'r+'), LOCK_EX) ? '1' : '0', "\n";
@unlink($path);
--EXPECT--
1
