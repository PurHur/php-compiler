--TEST--
AOT: fclose after fwrite flushes and returns true (#33426)
--FILE--
<?php
$path = sys_get_temp_dir() . '/fclose_fwrite_aot_phpt_' . getmypid() . '.txt';
@unlink($path);
$f = fopen($path, 'w');
fwrite($f, 'hi');
var_export(fclose($f));
echo "\n";
echo file_get_contents($path), "\n";
@unlink($path);
--EXPECT--
true
hi
