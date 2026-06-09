--TEST--
JIT: stream_copy_to_stream() via __compiler_stream_copy_to_stream (#3272)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-scopy-jit-' . getmypid() . '.txt';
file_put_contents($path, 'jit-copy');
$src = fopen($path, 'rb');
$dst = fopen('php://memory', 'wb+');
$n = stream_copy_to_stream($src, $dst);
rewind($dst);
echo $n, "\n", fread($dst, 8192), "\n";
fclose($src);
fclose($dst);
@unlink($path);
--EXPECT--
8
jit-copy
