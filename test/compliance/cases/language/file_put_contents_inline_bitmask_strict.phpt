--TEST--
file_put_contents() inline FILE_APPEND|LOCK_EX under strict_types (#18523, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/fpc_inline_bitmask_' . getmypid() . '.txt';
@unlink($path);
file_put_contents($path, 'a');
$r = file_put_contents($path, 'b', FILE_APPEND | LOCK_EX);
var_dump($r);
echo file_get_contents($path);
@unlink($path);
--EXPECT--
int(1)
ab
