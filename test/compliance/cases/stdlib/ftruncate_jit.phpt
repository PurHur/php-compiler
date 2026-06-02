--TEST--
stdlib ftruncate() JIT
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_ftruncate_jit.txt';
$fp = fopen($path, 'w+');
$nw = fwrite($fp, 'yyyyyyyyyyyy');
rewind($fp);
echo ftruncate($fp, 3) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
