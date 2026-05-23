--TEST--
AOT ftell() after fwrite
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_ftell_aot_');
$fp = fopen($path, 'w');
$n = fwrite($fp, 'z');
echo ftell($fp), "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
