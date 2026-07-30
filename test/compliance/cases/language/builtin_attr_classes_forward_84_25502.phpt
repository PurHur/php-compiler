--TEST--
Language: Deprecated/Override attribute classes on PHP_COMPILER_PROFILE=8.4 (#25502, Zend/zend_attributes.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
var_export(class_exists('Override', false));
echo "\n";
var_export(class_exists('NoDiscard', false));
echo "\n";

#[\Deprecated]
function h25502_84() {}
var_export((new ReflectionFunction('h25502_84'))->isDeprecated());
echo "\n";
--EXPECT--
true
true
false
true
