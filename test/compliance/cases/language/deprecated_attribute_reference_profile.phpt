--TEST--
Language: Deprecated attribute class absent on 8.2 reference profile (#12588, Zend/zend_attributes.c)
--FILE--
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
--EXPECT--
false
