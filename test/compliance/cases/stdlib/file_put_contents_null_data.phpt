--TEST--
stdlib file_put_contents() null $data — coerces to empty string (#17024, ext/standard/file.c)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'fpc_null_');
$n = file_put_contents($path, null);
$size = filesize($path);
unlink($path);
var_export($n);
echo "\n";
var_export($size);
echo "\n";
?>
--EXPECT--
0
0
