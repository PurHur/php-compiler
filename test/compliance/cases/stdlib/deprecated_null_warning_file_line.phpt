--TEST--
stdlib null-to-scalar E_DEPRECATED cites call-site file and line (#16409, Zend/zend.c)
--FILE--
<?php
error_reporting(E_ALL);
strlen(null);
trim(null);
strtolower(null);
--EXPECTF--
PHP Deprecated:  strlen(): Passing null to parameter #1 ($string) of type string is deprecated in %s on line %d
PHP Deprecated:  trim(): Passing null to parameter #1 ($string) of type string is deprecated in %s on line %d
PHP Deprecated:  strtolower(): Passing null to parameter #1 ($string) of type string is deprecated in %s on line %d
