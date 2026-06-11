--TEST--
JIT: stream_copy_to_string() via __compiler_stream_copy_to_string (#6547)
--SKIPIF--
<?php if (!function_exists('stream_copy_to_string')) die('skip host PHP lacks stream_copy_to_string'); ?>
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-scts-jit-' . getmypid() . '.txt';
file_put_contents($path, 'jit copy');
$src = fopen($path, 'rb');
echo stream_copy_to_string($src), "\n";
echo stream_copy_to_string($src, 3, 0), "\n";
fclose($src);
@unlink($path);
--EXPECT--
jit copy
jit
--CREDITS--
PurHur/php-compiler #6547
