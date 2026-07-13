--TEST--
stdlib ini_get()/get_cfg_var(null) — false not TypeError (#18742, ext/standard/ini.c)
--FILE--
<?php
var_export(ini_get(null));
echo "\n";
var_export(ini_set(null, '1'));
echo "\n";
var_export(get_cfg_var(null));
echo "\n";
?>
--EXPECT--
false
false
false
