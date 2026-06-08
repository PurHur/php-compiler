--TEST--
posix_mknod() FIFO probe (VM, issue #7373)
--SKIPIF--
<?php if (!function_exists('posix_mknod')) die('skip no host posix_mknod'); ?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('posix_mknod'), "\n";
$path = sys_get_temp_dir() . '/phpc_posix_mknod_' . getmypid();
@unlink($path);
var_export(posix_mknod($path, S_IFIFO | 0644, 0));
echo "\n";
var_export(file_exists($path));
@unlink($path);
echo "\n";
?>
--EXPECT--
1
true
true
