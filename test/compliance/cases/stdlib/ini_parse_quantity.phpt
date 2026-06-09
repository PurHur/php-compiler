--TEST--
stdlib ini_parse_quantity() — ini byte shorthand parser (#6049, Zend/zend_ini.c)
--FILE--
<?php
var_export(ini_parse_quantity('1G'));
echo "\n";
var_export(ini_parse_quantity('512M'));
echo "\n";
var_export(ini_parse_quantity('2K'));
echo "\n";
var_export(ini_parse_quantity('0'));
echo "\n";
var_export(ini_parse_quantity('-1G'));
echo "\n";
@ini_parse_quantity('not-a-quantity');
echo "warned\n";
--EXPECT--
1073741824
536870912
2048
0
-1073741824
warned
