--TEST--
language: Closure::bindTo() implicit internal $this scope returns bound closure (#12562, zend_closures.c)
--FILE--
<?php
$c = function (): int { return 42; };
var_dump($c->bindTo(new stdClass()) instanceof Closure);
var_dump($c->bindTo(new ArrayObject()) instanceof Closure);
var_dump($c->bindTo(new stdClass(), 'stdClass'));
--EXPECTF--
PHP Warning:  Cannot bind closure to scope of internal class stdClass in %s on line %d
bool(true)
bool(true)
NULL
