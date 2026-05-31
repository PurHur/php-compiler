--TEST--
AOT: ftruncate() shortens open file
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_ftruncate.txt';
$wf = fopen($path, 'w');
$nw = fwrite($wf, 'yyyyyyyyyyyy');
fclose($wf);
$fp = fopen($path, 'r+');
echo ftruncate($fp, 3) ? '1' : '0', "\n";
fclose($fp);
echo file_get_contents($path), "\n";
@unlink($path);
--EXPECT--
1
yyy
