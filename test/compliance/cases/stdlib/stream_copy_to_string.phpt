--TEST--
stream_copy_to_string() — read file stream into string (#6547)
--SKIPIF--
<?php if (!function_exists('stream_copy_to_string')) die('skip host PHP lacks stream_copy_to_string'); ?>
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-scts-' . getmypid() . '.txt';
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
echo function_exists('stream_copy_to_string') ? '1' : '0', "\n";
echo stream_copy_to_string($src), "\n";
fclose($src);
@unlink($path);

// maxlength spot-check
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
echo stream_copy_to_string($src, 5), "\n";
fclose($src);
@unlink($path);

// offset spot-check
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
echo stream_copy_to_string($src, -1, 6), "\n";
fclose($src);
@unlink($path);

// zero maxlength
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
echo stream_copy_to_string($src, 0), "\n";
fclose($src);
@unlink($path);
--EXPECT--
1
hello world
hello
world

--CREDITS--
PurHur/php-compiler #6547
