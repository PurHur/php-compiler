--TEST--
stdlib class_alias() failure paths emit E_WARNING (#13353, ext/standard/basic_functions.c)
--FILE--
<?php
class Target13353 {}
var_export(class_alias('NoSuchClass', 'AliasMissing13353'));
echo "\n";
var_export(class_alias('Target13353', 'AliasDup13353'));
echo "\n";
var_export(class_alias('Target13353', 'AliasDup13353'));
echo "\n";
--EXPECT--
PHP Warning:  Class "NoSuchClass" not found
PHP Warning:  Cannot declare class AliasDup13353, because the name is already in use
false
true
false
