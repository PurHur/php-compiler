--TEST--
stdlib: Closure::bindTo() unknown scope class warns and returns null (#6704, zend_closures.c)
--FILE--
<?php
$c = function () {
    return 1;
};
var_dump($c->bindTo(new stdClass(), 'MissingScopeClass'));
--EXPECTF--
PHP Warning:  Class "MissingScopeClass" not found
NULL
