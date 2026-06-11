--TEST--
posix_mkfifo() — FIFO special file creation (VM, issue #6667)
--SKIPIF--
<?php if (!function_exists('posix_mkfifo')) die('skip no host posix_mkfifo'); ?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('posix_mkfifo'), "\n";
$path = sys_get_temp_dir() . '/phpc_posix_mkfifo_' . getmypid();
@unlink($path);
var_export(posix_mkfifo($path, 0600));
echo "\n";
var_export(file_exists($path));
@unlink($path);
echo "\n";
?>
--EXPECT--
1
true
true
