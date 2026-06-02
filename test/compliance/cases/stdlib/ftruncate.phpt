--TEST--
stdlib ftruncate() shortens open file
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftruncate_');
$wf = fopen($path, 'w');
$nw = fwrite($wf, 'xxxxxxxxxxxxxxxxxxxx');
fclose($wf);
$fp = fopen($path, 'r+');
echo ftruncate($fp, 5) ? '1' : '0', "\n";
fclose($fp);
echo file_get_contents($path), "\n";
@unlink($path);
--EXPECT--
1
xxxxx
