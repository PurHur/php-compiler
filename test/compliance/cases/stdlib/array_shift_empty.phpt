--TEST--
stdlib array_shift() on empty array — silent like Zend PHP 8.2+ (#10322)
--FILE--
<?php
error_reporting(E_ALL);
$b = [];
array_shift($b);
var_export(error_get_last());
echo "\n";
--EXPECT--
NULL
