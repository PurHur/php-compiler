--TEST--
Stdlib: attribute_exists() — class attribute probe (VM, #6468, ext/reflection/php_reflection.c)
--FILE--
<?php
#[\AllowDynamicProperties]
class Demo {}

var_export(function_exists('attribute_exists'));
echo "\n";
var_export(attribute_exists(AllowDynamicProperties::class, Demo::class));
echo "\n";
var_export(attribute_exists('AllowDynamicProperties', Demo::class));
echo "\n";
var_export(attribute_exists('\\AllowDynamicProperties', Demo::class));
echo "\n";
var_export(attribute_exists('NoSuchAttribute', Demo::class));
echo "\n";
var_export(attribute_exists(AllowDynamicProperties::class, 'NoSuchClass'));
echo "\n";
--EXPECT--
true
true
true
true
false
false
