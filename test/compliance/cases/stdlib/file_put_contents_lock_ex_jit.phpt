--TEST--
JIT: file_put_contents() LOCK_EX and FILE_APPEND|LOCK_EX (#4275)
--FILE--
<?php
$base = sys_get_temp_dir() . '/fpc_lock_jit_' . getmypid();
$path = $base . '.txt';
$n = file_put_contents($path, 'x', LOCK_EX);
echo $n, "\n";
file_put_contents($path, 'y', FILE_APPEND | LOCK_EX);
echo file_get_contents($path);
unlink($path);
--EXPECT--
1
xy
