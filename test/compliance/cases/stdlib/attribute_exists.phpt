--TEST--
Stdlib: attribute_exists() — class attribute probe (VM, #6468, ext/reflection/php_reflection.c)
--FILE--
<?php
#[\AllowDynamicProperties]
class Demo {}

var_export(function_exists('attribute_exists'));
echo "\n";
var_export(attribute_exists(Demo::class, AllowDynamicProperties::class));
echo "\n";
var_export(attribute_exists(Demo::class, 'AllowDynamicProperties'));
echo "\n";
var_export(attribute_exists(Demo::class, '\\AllowDynamicProperties'));
echo "\n";
var_export(attribute_exists(Demo::class, 'NoSuchAttribute'));
echo "\n";
var_export(attribute_exists('NoSuchClass', AllowDynamicProperties::class));
echo "\n";
--EXPECT--
true
true
true
true
false
false
