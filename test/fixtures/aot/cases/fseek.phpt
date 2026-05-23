--TEST--
AOT fseek() after fwrite
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_fseek_aot_');
$fp = fopen($path, 'w');
fwrite($fp, 'abc');
echo fseek($fp, 0) === 0 ? '0' : '1', "\n";
echo ftell($fp) === 0 ? '0' : '1', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
0
0
