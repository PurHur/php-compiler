--TEST--
language FiberStackOverflow absent on 8.2 reference profile (#26741, Zend/zend_fibers.c)
--FILE--
<?php
var_export(class_exists('FiberStackOverflow', false));
echo "\n";
var_export(in_array('FiberStackOverflow', get_declared_classes(), true));
echo "\n";
--EXPECT--
false
false
