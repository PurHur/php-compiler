--TEST--
stdlib file_put_contents() — null $data coerces to empty string (#17024, ext/standard/file.c Z_PARAM_STR)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_fpc_null_data_' . getmypid() . '.txt';
@unlink($path);
error_reporting(E_ALL & ~E_DEPRECATED);
$n = file_put_contents($path, null);
echo var_export($n, true), "\n";
@unlink($path);
--EXPECT--
0
