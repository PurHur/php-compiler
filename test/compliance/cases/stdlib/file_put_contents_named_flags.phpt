--TEST--
file_put_contents() FILE_APPEND and LOCK_EX named constants (#9589, ext/standard/file.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/fpc_named_flags_' . getmypid() . '.txt';
file_put_contents($path, 'a');
file_put_contents($path, 'b', FILE_APPEND);
echo file_get_contents($path), "\n";
file_put_contents($path, 'x', FILE_APPEND | LOCK_EX);
echo file_get_contents($path);
unlink($path);
--EXPECT--
ab
abx
