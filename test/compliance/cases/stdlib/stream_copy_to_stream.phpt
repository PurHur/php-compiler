--TEST--
stream_copy_to_stream() — copy file stream to memory (#3272)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-scopy-' . getmypid() . '.txt';
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
$dst = fopen('php://memory', 'wb+');
echo function_exists('stream_copy_to_stream') ? '1' : '0', "\n";
$n = stream_copy_to_stream($src, $dst);
rewind($dst);
echo $n, "\n", fread($dst, 8192), "\n";
fclose($src);
fclose($dst);
@unlink($path);

// maxlength spot-check
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
$dst = fopen('php://memory', 'wb+');
$n = stream_copy_to_stream($src, $dst, 5);
rewind($dst);
echo $n, "\n", fread($dst, 8192), "\n";
fclose($src);
fclose($dst);
@unlink($path);

// offset spot-check
file_put_contents($path, 'hello world');
$src = fopen($path, 'rb');
$dst = fopen('php://memory', 'wb+');
$n = stream_copy_to_stream($src, $dst, -1, 6);
rewind($dst);
echo $n, "\n", fread($dst, 8192), "\n";
fclose($src);
fclose($dst);
@unlink($path);
--EXPECT--
1
11
hello world
5
hello
5
world
