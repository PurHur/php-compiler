--TEST--
Language: define('true', 1) warns rather than compile-fatal (#32228, zend_constants.c)
--FILE--
<?php
error_reporting(E_ALL);
define('true', 1);
echo "ran\n";
--EXPECTF--
PHP Warning:  Constant true already defined in %s on line %d
ran
