--TEST--
stdlib ErrorException::__construct() + getSeverity() (#6732, Zend/zend_exceptions.c)
--FILE--
<?php
var_export(class_exists('ErrorException'));
echo "\n";
$e = new ErrorException('m', 0, E_USER_WARNING, __FILE__, 42);
echo $e->getSeverity(), "\n";
$e2 = new ErrorException('probe', 0, E_USER_WARNING, __FILE__, 99);
echo $e2->getMessage(), ':', $e2->getSeverity(), "\n";
--EXPECT--
true
512
probe:512
