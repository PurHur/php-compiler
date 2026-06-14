--TEST--
file_put_contents() LOCK_EX writes atomically (VM, #4275)
--FILE--
<?php
$path = sys_get_temp_dir() . '/fpc_lock_vm_' . getmypid() . '.txt';
$n = file_put_contents($path, "a\n", LOCK_EX);
echo $n, "\n";
echo file_get_contents($path);
unlink($path);
--EXPECT--
2
a
