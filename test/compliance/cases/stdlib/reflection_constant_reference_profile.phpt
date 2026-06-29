--TEST--
ReflectionConstant phantom on 8.2 reference profile (#13497, ext/reflection/php_reflection.c)
--FILE--
<?php
var_export(class_exists('ReflectionConstant', false));
echo "\n";
--EXPECT--
false
