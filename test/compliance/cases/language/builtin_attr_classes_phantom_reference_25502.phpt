--TEST--
Language: Deprecated/Override attribute classes absent on 8.2 reference profile (#25502, re-#11902/#12588, Zend/zend_attributes.c)
--FILE--
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
var_export(class_exists('Override', false));
echo "\n";
var_export(class_exists('NoDiscard', false));
echo "\n";
var_export(class_exists('SensitiveParameter', false));
echo "\n";
var_export(class_exists('AllowDynamicProperties', false));
echo "\n";

#[\Deprecated]
function h25502() {}
var_export((new ReflectionFunction('h25502'))->isDeprecated());
echo "\n";
--EXPECT--
false
false
false
true
true
false
