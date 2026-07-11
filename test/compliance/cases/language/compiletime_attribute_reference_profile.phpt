--TEST--
Language: CompileTime/DelayedTargetValidation absent on 8.2 reference profile (#12598, Zend/zend_attributes.c)
--FILE--
<?php
var_export(class_exists('CompileTime', false));
echo "\n";
var_export(class_exists('DelayedTargetValidation', false));
echo "\n";
--EXPECT--
false
false
