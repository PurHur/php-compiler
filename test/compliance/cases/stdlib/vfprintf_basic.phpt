--TEST--
stdlib vfprintf() and vprintf() with args array
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_vfprintf_' . getmypid() . '.txt';
$fp = fopen($path, 'w+');
$n = vfprintf($fp, '%s-%d', ['a', 3]);
fclose($fp);
echo file_get_contents($path), " bytes=$n\n";
@unlink($path);
vprintf("ok-%s\n", ['done']);
--EXPECT--
a-3 bytes=3
ok-done
