--TEST--
stdlib get_cfg_var('cfg_file_path') matches php_ini_loaded_file() (#10179)
--FILE--
<?php
$cfg = get_cfg_var('cfg_file_path');
$loaded = php_ini_loaded_file();
echo ($cfg !== false && is_string($cfg)) ? "cfg_path_ok\n" : "cfg_path_fail\n";
echo ($loaded !== false && is_string($loaded)) ? "loaded_ok\n" : "loaded_fail\n";
echo $cfg === $loaded ? "paths_match\n" : "paths_mismatch\n";
--EXPECT--
cfg_path_ok
loaded_ok
paths_match
