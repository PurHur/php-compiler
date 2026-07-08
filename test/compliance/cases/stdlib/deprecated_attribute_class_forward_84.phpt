--TEST--
stdlib Deprecated builtin attribute class on PHP_COMPILER_PROFILE=8.4 (#17318, Zend/zend_attributes.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
var_export((new ReflectionClass('Deprecated'))->isInternal());
echo "\n";
--EXPECT--
true
true
