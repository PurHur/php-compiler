--TEST--
language: Closure::fromCallable() bindTo() internal class scope warns (#18192, zend_closures.c)
--FILE--
<?php
$c = Closure::fromCallable('strlen');
$b = $c->bindTo(new stdClass(), stdClass::class);
var_dump($b);
--EXPECTF--
PHP Warning:  Cannot bind closure to scope of internal class stdClass in %s on line %d
NULL
