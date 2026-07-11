--TEST--
language: Closure::bind() internal class scope warns and returns null (#5011, zend_closures.c)
--FILE--
<?php
$fn = function () { return 1; };
var_dump(Closure::bind($fn, new stdClass(), 'stdClass'));
--EXPECTF--
PHP Warning:  Cannot bind closure to scope of internal class stdClass in %s on line %d
NULL
