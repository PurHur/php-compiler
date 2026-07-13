<?php
// Zend coerces null option names to "" and returns false (ext/standard/ini.c, #18742).

var_export(ini_get(null));
echo "\n";
var_export(ini_set(null, '1'));
echo "\n";
var_export(get_cfg_var(null));
echo "\n";
