--TEST--
language: Closure::call() on internal class warns and returns null (#7127, zend_closures.c)
--FILE--
<?php
$fn = function () { return 42; };
var_dump($fn->call(new stdClass()));
--EXPECTF--
PHP Warning:  Cannot bind closure to scope of internal class stdClass in %s on line %d
NULL
